<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('audit.view_all');

// Owner sees business activity; system-only modules (admin user/backup management)
// stay Admin-only — enforced here at the query level since audit_logs has no
// separate "system vs business" permission (see migration 003 for why both
// roles share audit.view_all).
$isAdmin = hasRole(ROLE_ADMIN);

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'module' => $_GET['module'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];

$where = ['1=1'];
$params = [];
if (!$isAdmin) {
    $where[] = "a.module != 'admin'";
}
if ($filters['q'] !== '') {
    $where[] = '(a.action LIKE :q OR u.full_name LIKE :q)';
    $params['q'] = '%' . $filters['q'] . '%';
}
if ($filters['module'] !== '') {
    $where[] = 'a.module = :module';
    $params['module'] = $filters['module'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'DATE(a.created_at) >= :date_from';
    $params['date_from'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'DATE(a.created_at) <= :date_to';
    $params['date_to'] = $filters['date_to'];
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT a.*, u.full_name, u.username
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     WHERE $whereSql ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$pages = max(1, (int) ceil($total / $perPage));

$modules = db()->query('SELECT DISTINCT module FROM audit_logs' . (!$isAdmin ? " WHERE module != 'admin'" : '') . ' ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Trail';
$activeNav = 'audit';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Audit Trail</h1>
    <p class="text-muted mb-0 small"><?= $isAdmin ? 'All system and business activity.' : 'Business activity across the shop.' ?></p>
</div>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Action or user name" value="<?= htmlspecialchars($filters['q']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Module</label>
            <select name="module" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $filters['module'] === $m ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($m)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to']) ?>">
        </div>
        <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button></div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$logs): ?>
        <div class="empty-state"><i class="bi bi-journal-text"></i><p class="mb-0">No audit activity matches your filters.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Module</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="small text-muted"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($log['created_at']))) ?></td>
                        <td class="small"><?= htmlspecialchars($log['full_name'] ?? 'System') ?></td>
                        <td class="small"><?= htmlspecialchars($log['action']) ?></td>
                        <td><span class="badge-status badge-neutral"><?= htmlspecialchars($log['module']) ?></span></td>
                        <td class="small text-muted" style="max-width:320px; overflow-wrap:anywhere;">
                            <?php if ($log['details']): $d = json_decode($log['details'], true); ?>
                                <?= $d ? htmlspecialchars(implode(', ', array_map(fn($k, $v) => "$k: " . (is_array($v) ? json_encode($v) : $v), array_keys($d), $d))) : htmlspecialchars($log['details']) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
<?php require __DIR__ . '/../components/footer.php'; ?>

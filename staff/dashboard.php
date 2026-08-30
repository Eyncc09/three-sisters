<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../auth/middleware.php';
requireRole(ROLE_STAFF);

$pdo = db();
$mySalesToday = (int) $pdo->prepare(
    "SELECT COUNT(*) FROM sales WHERE cashier_id = :uid AND DATE(created_at) = CURDATE() AND status = 'completed'"
);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE cashier_id = :uid AND DATE(created_at) = CURDATE() AND status = 'completed'");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$mySalesToday = (int) $stmt->fetchColumn();

require_once __DIR__ . '/../core/OrderService.php';
$recentSales = OrderService::recentSales((int) $_SESSION['user_id'], 5);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Hi, <?= htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]) ?></h1>
    <p class="text-muted mb-0 small">Ready for today's sales.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="stat-label">My Sales Today</div>
                <div class="stat-value"><?= $mySalesToday ?></div>
            </div>
        </div>
    </div>
</div>

<div class="ts-card p-4 text-center mb-3">
    <i class="bi bi-cash-coin" style="font-size:2rem;color:var(--ts-accent);"></i>
    <p class="mt-2 mb-3 text-muted">Ready to ring up a sale?</p>
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/pos/index.php"><i class="bi bi-cart3 me-1"></i>Open POS</a>
</div>

<div class="ts-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Your Recent Sales</h2>
        <a href="<?= BASE_URL ?>/orders/index.php" class="small text-decoration-none">Full History →</a>
    </div>
    <?php if (!$recentSales): ?>
        <div class="empty-state py-3"><i class="bi bi-receipt"></i><p class="mb-0 small">No sales recorded yet.</p></div>
    <?php else: ?>
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Sale #</th><th>Time</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentSales as $s): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars($s['sale_number']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars(date('M j, g:ia', strtotime($s['created_at']))) ?></td>
                    <td>₱<?= number_format((float) $s['total_amount'], 2) ?></td>
                    <td><span class="badge-status <?= $s['payment_status'] === 'verified' ? 'badge-success' : ($s['payment_status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>"><?= ucfirst($s['payment_status'] ?? '—') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

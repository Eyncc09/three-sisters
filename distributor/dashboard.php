<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../auth/middleware.php';
requireRole(ROLE_DISTRIBUTOR);

require_once __DIR__ . '/../core/AuditLogger.php';
require_once __DIR__ . '/../core/InventoryService.php';
require_once __DIR__ . '/../core/AnalyticsService.php';
require_once __DIR__ . '/../core/DistributorService.php';
require_once __DIR__ . '/../core/PurchaseOrderService.php';
require_once __DIR__ . '/../core/ChatService.php';

$distributorId = (int) ($_SESSION['distributor_id'] ?? 0);
$distributor = $distributorId ? DistributorService::find($distributorId) : null;

$openPOs = [];
$unreadMessages = 0;
$suppliedProducts = [];
if ($distributorId && $distributor) {
    $openPOs = array_values(array_filter(
        PurchaseOrderService::all([], $distributorId),
        fn($po) => !in_array($po['status'], ['delivered', 'cancelled'], true)
    ));
    $unreadMessages = ChatService::unreadCountForUser((int) $_SESSION['user_id'], ROLE_DISTRIBUTOR, $distributorId);
    $suppliedProducts = DistributorService::suppliedProducts($distributorId);
}

$poBadge = ['requested' => 'badge-neutral', 'confirmed' => 'badge-info', 'preparing' => 'badge-info', 'shipped' => 'badge-info', 'delivered' => 'badge-success', 'cancelled' => 'badge-danger'];

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
    <p class="text-muted mb-0 small">Distributor Portal<?= $distributor ? ' — ' . htmlspecialchars($distributor['name']) : '' ?></p>
</div>

<?php if (!$distributor): ?>
    <div class="ts-card p-4 text-center">
        <i class="bi bi-exclamation-triangle" style="font-size:2rem;color:var(--ts-warning);"></i>
        <p class="mt-2 mb-0 text-muted">Your account is not linked to a distributor profile. Please contact the shop owner.</p>
    </div>
<?php else: ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card"><div class="stat-label">Open Purchase Orders</div><div class="stat-value"><?= count($openPOs) ?></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card"><div class="stat-label">Products Supplied</div><div class="stat-value"><?= count($suppliedProducts) ?></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card">
            <div class="stat-label">Unread Messages</div>
            <div class="stat-value"><?= $unreadMessages ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card"><div class="stat-label">Your Lead Time</div><div class="stat-value" style="font-size:1.3rem;"><?= (int) $distributor['lead_time_days'] ?> days</div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="ts-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Your Open Purchase Orders</h2>
                <a href="<?= BASE_URL ?>/distributors/purchase-orders.php" class="small text-decoration-none">View All →</a>
            </div>
            <?php if (!$openPOs): ?>
                <div class="empty-state py-4"><i class="bi bi-truck"></i><p class="mb-0 small">No open purchase orders right now.</p></div>
            <?php else: ?>
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>PO #</th><th>Expected Delivery</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($openPOs, 0, 8) as $po): ?>
                        <tr>
                            <td class="small fw-semibold"><?= htmlspecialchars($po['po_number']) ?></td>
                            <td class="small"><?= $po['expected_delivery_date'] ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '—' ?></td>
                            <td><span class="badge-status <?= $poBadge[$po['status']] ?>"><?= ucfirst($po['status']) ?></span></td>
                            <td class="text-end"><a href="<?= BASE_URL ?>/distributors/po-view.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Quick Actions</h2>
            <div class="d-grid gap-2">
                <a href="<?= BASE_URL ?>/distributors/view.php?id=<?= $distributorId ?>" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-person-vcard me-2"></i>My Profile &amp; Products</a>
                <a href="<?= BASE_URL ?>/distributors/chat.php?distributor_id=<?= $distributorId ?>" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-chat-dots me-2"></i>Message Owner</a>
                <a href="<?= BASE_URL ?>/distributors/purchase-orders.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-truck me-2"></i>All Purchase Orders</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../components/footer.php'; ?>

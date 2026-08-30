<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../auth/middleware.php';
requireRole(ROLE_OWNER);

$pdo = db();
$productCount   = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$lowStockCount  = (int) $pdo->query(
    "SELECT COUNT(*) FROM inventory i JOIN products p ON p.id = i.product_id
     WHERE p.status = 'active' AND i.quantity_on_hand <= p.reorder_level"
)->fetchColumn();
$expiringCount  = (int) $pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active' AND expiration_date IS NOT NULL
     AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiration_date >= CURDATE()"
)->fetchColumn();
$customerCount  = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$resellerCount  = (int) $pdo->query("SELECT COUNT(*) FROM resellers WHERE status = 'active'")->fetchColumn();

$recentAlerts = $pdo->query(
    "SELECT sa.*, p.name AS product_name, p.sku FROM stock_alerts sa
     JOIN products p ON p.id = sa.product_id
     WHERE sa.status = 'active' ORDER BY sa.created_at DESC LIMIT 6"
)->fetchAll();

require_once __DIR__ . '/../core/PurchaseOrderService.php';
require_once __DIR__ . '/../core/ChatService.php';
$upcomingDeliveries = PurchaseOrderService::upcomingDeliveries(14);
$unreadDistributorMessages = ChatService::unreadCountForUser((int) $_SESSION['user_id'], ROLE_OWNER);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]) ?></h1>
        <p class="text-muted mb-0 small">Here's what's happening at <?= htmlspecialchars(APP_NAME) ?> today.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
            <div>
                <div class="stat-label">Active Products</div>
                <div class="stat-value"><?= $productCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:var(--ts-warning-bg); color:var(--ts-warning);"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-label">Low / Critical Stock</div>
                <div class="stat-value"><?= $lowStockCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:var(--ts-danger-bg); color:var(--ts-danger);"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-label">Expiring Soon (30d)</div>
                <div class="stat-value"><?= $expiringCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:var(--ts-info-bg); color:var(--ts-info);"><i class="bi bi-people"></i></div>
            <div>
                <div class="stat-label">Customers / Resellers</div>
                <div class="stat-value"><?= $customerCount ?> / <?= $resellerCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Active Stock &amp; Expiration Alerts</h2>
            <?php if (!$recentAlerts): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-check2-circle"></i>
                    <p class="mb-0">No active alerts right now. Inventory looks healthy.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Product</th><th>SKU</th><th>Alert</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($recentAlerts as $alert): ?>
                            <tr>
                                <td><?= htmlspecialchars($alert['product_name']) ?></td>
                                <td class="text-muted"><?= htmlspecialchars($alert['sku']) ?></td>
                                <td><?= htmlspecialchars($alert['message']) ?></td>
                                <td>
                                    <?php
                                        $badgeClass = in_array($alert['alert_type'], ['critical_stock', 'expired']) ? 'badge-danger' : 'badge-warning';
                                    ?>
                                    <span class="badge-status <?= $badgeClass ?>"><?= str_replace('_', ' ', $alert['alert_type']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ts-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Supply Chain</h2>
                <a href="<?= BASE_URL ?>/distributors/index.php" class="small text-decoration-none">Distributors →</a>
            </div>
            <?php if ($unreadDistributorMessages > 0): ?>
                <a href="<?= BASE_URL ?>/distributors/chat.php" class="d-flex align-items-center justify-content-between text-decoration-none mb-2 p-2" style="background:var(--ts-accent-tint); border-radius:0.5rem;">
                    <span class="small text-dark"><i class="bi bi-chat-dots me-1"></i>Unread distributor messages</span>
                    <span class="badge-status badge-danger"><?= $unreadDistributorMessages ?></span>
                </a>
            <?php endif; ?>
            <?php if (!$upcomingDeliveries): ?>
                <p class="text-muted small mb-0">No deliveries expected in the next 14 days.</p>
            <?php else: ?>
                <p class="text-muted small mb-2">Upcoming deliveries (next 14 days):</p>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach (array_slice($upcomingDeliveries, 0, 5) as $d): ?>
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <a href="<?= BASE_URL ?>/distributors/po-view.php?id=<?= $d['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($d['po_number']) ?> — <?= htmlspecialchars($d['distributor_name']) ?></a>
                            <span class="text-muted"><?= htmlspecialchars(date('M j', strtotime($d['expected_delivery_date']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Quick Actions</h2>
            <div class="d-grid gap-2">
                <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-primary btn-sm text-start"><i class="bi bi-cart3 me-2"></i>Open POS</a>
                <a href="<?= BASE_URL ?>/products/add.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-plus-circle me-2"></i>Add New Product</a>
                <a href="<?= BASE_URL ?>/inventory/index.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-box-arrow-in-down me-2"></i>Record Stock-In</a>
                <a href="<?= BASE_URL ?>/customers/index.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-person-plus me-2"></i>Add Customer</a>
                <a href="<?= BASE_URL ?>/resellers/index.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-shop me-2"></i>Manage Resellers</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

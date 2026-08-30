<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();

// This page is intentionally permission-free beyond login: `notifications`
// rows are already scoped per-user/per-role by query, matching the schema's
// own design (user_id for a specific recipient, OR role_id for a broadcast).
//
// Honesty note: nothing currently WRITES to the `notifications` table (no
// background job creates rows — this project has no cron). Rather than show
// a permanently-empty page, this view also live-derives alerts directly from
// real current data (stock_alerts, expiring products, pending payments) —
// every row shown is a real, current fact from the database, never a
// fabricated example.

$stmt = db()->prepare(
    'SELECT * FROM notifications WHERE user_id = :uid OR role_id = :role_id ORDER BY created_at DESC LIMIT 30'
);
$stmt->execute(['uid' => $_SESSION['user_id'], 'role_id' => $_SESSION['role_id']]);
$storedNotifications = $stmt->fetchAll();

$liveAlerts = [];

// Low/critical stock — Owner and Staff (Staff can see, not act).
if (can('inventory.adjust') || can('inventory.receive') || can('products.view')) {
    $stmt = db()->query(
        "SELECT sa.*, p.name AS product_name, p.sku FROM stock_alerts sa
         JOIN products p ON p.id = sa.product_id WHERE sa.status = 'active' ORDER BY sa.created_at DESC LIMIT 10"
    );
    foreach ($stmt->fetchAll() as $r) {
        $liveAlerts[] = [
            'type' => in_array($r['alert_type'], ['critical_stock'], true) ? 'danger' : 'warning',
            'icon' => 'bi-exclamation-triangle',
            'title' => str_replace('_', ' ', ucfirst($r['alert_type'])),
            'text' => $r['message'],
            'time' => $r['created_at'],
        ];
    }
}

// Expiring/expired products — same audience.
if (can('inventory.adjust') || can('inventory.receive') || can('products.view')) {
    $stmt = db()->query(
        "SELECT name, sku, expiration_date FROM products
         WHERE status = 'active' AND expiration_date IS NOT NULL
           AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY expiration_date ASC LIMIT 10"
    );
    foreach ($stmt->fetchAll() as $r) {
        $isExpired = strtotime($r['expiration_date']) < strtotime('today');
        $liveAlerts[] = [
            'type' => $isExpired ? 'danger' : 'warning',
            'icon' => 'bi-hourglass-split',
            'title' => $isExpired ? 'Expired' : 'Expiring Soon',
            'text' => $r['name'] . ' (' . $r['sku'] . ') — ' . date('M j, Y', strtotime($r['expiration_date'])),
            'time' => null,
        ];
    }
}

// Pending payment verification — Owner only.
if (can('payments.verify')) {
    $stmt = db()->query(
        "SELECT p.*, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id
         WHERE p.status = 'pending' ORDER BY p.created_at ASC LIMIT 10"
    );
    foreach ($stmt->fetchAll() as $r) {
        $liveAlerts[] = [
            'type' => 'info',
            'icon' => 'bi-credit-card',
            'title' => 'Payment Pending Verification',
            'text' => htmlspecialchars($r['order_number']) . ' — ' . ucfirst($r['payment_method']) . ' ₱' . number_format((float) $r['amount'], 2),
            'time' => $r['created_at'],
        ];
    }
}

// Upcoming/overdue distributor deliveries — Owner and the assigned Distributor.
if (can('distributors.manage') || (hasRole(ROLE_DISTRIBUTOR) && can('distributors.portal'))) {
    require_once __DIR__ . '/../core/PurchaseOrderService.php';
    $deliveries = PurchaseOrderService::upcomingDeliveries(7);
    if (hasRole(ROLE_DISTRIBUTOR)) {
        $deliveries = array_values(array_filter($deliveries, fn($d) => (int) $d['distributor_id'] === (int) ($_SESSION['distributor_id'] ?? 0)));
    }
    foreach ($deliveries as $d) {
        $liveAlerts[] = [
            'type' => 'info',
            'icon' => 'bi-truck',
            'title' => 'Upcoming Delivery',
            'text' => htmlspecialchars($d['po_number']) . ' — ' . htmlspecialchars($d['distributor_name']) . ' expected ' . date('M j', strtotime($d['expected_delivery_date'])),
            'time' => null,
        ];
    }
}

$typeBadge = ['danger' => 'badge-danger', 'warning' => 'badge-warning', 'info' => 'badge-info', 'success' => 'badge-success'];

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Notifications</h1>
    <p class="text-muted mb-0 small">Alerts relevant to your role, generated from current data.</p>
</div>

<div class="ts-card p-3 mb-3">
    <h2 class="h6 mb-3">Current Alerts</h2>
    <?php if (!$liveAlerts): ?>
        <div class="empty-state py-4"><i class="bi bi-check2-circle"></i><p class="mb-0">Nothing needs your attention right now.</p></div>
    <?php else: ?>
        <ul class="list-unstyled mb-0">
            <?php foreach ($liveAlerts as $a): ?>
                <li class="d-flex align-items-start gap-2 py-2 border-bottom">
                    <i class="bi <?= $a['icon'] ?> mt-1" style="color: var(--ts-<?= $a['type'] ?>);"></i>
                    <div>
                        <span class="badge-status <?= $typeBadge[$a['type']] ?>"><?= htmlspecialchars($a['title']) ?></span>
                        <div class="small mt-1"><?= $a['text'] ?></div>
                        <?php if ($a['time']): ?><div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars(date('M j, g:ia', strtotime($a['time']))) ?></div><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="ts-card p-3">
    <h2 class="h6 mb-3">Notification History</h2>
    <?php if (!$storedNotifications): ?>
        <div class="empty-state py-3"><i class="bi bi-bell"></i><p class="mb-0 small">No stored notifications yet.</p></div>
    <?php else: ?>
        <ul class="list-unstyled mb-0">
            <?php foreach ($storedNotifications as $n): ?>
                <li class="d-flex justify-content-between align-items-start py-2 border-bottom">
                    <div>
                        <div class="small fw-semibold"><?= htmlspecialchars($n['title']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($n['message']) ?></div>
                    </div>
                    <div class="text-muted text-end" style="font-size:0.7rem; min-width:100px;"><?= htmlspecialchars(date('M j, g:ia', strtotime($n['created_at']))) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

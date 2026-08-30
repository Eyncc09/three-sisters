<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('inventory.adjust') && !can('inventory.receive')) { renderForbidden(); }

$movements = InventoryService::recentMovements(null, 100);

$typeBadge = [
    'stock_in' => 'badge-success', 'sale' => 'badge-info', 'reseller_order' => 'badge-info',
    'tiktok_order' => 'badge-info', 'return' => 'badge-warning', 'damaged' => 'badge-danger',
    'expired' => 'badge-danger', 'adjustment' => 'badge-neutral',
];

$pageTitle = 'Stock Movement History';
$activeNav = 'inventory';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/inventory/index.php" class="text-decoration-none">Inventory</a>&nbsp;/&nbsp;Movement History</nav>
    <h1 class="page-title h4 mb-0">Stock Movement History</h1>
    <p class="text-muted mb-0 small">Most recent 100 movements across all products.</p>
</div>

<div class="ts-card">
    <?php if (!$movements): ?>
        <div class="empty-state"><i class="bi bi-arrow-left-right"></i><p class="mb-0">No stock movements recorded yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Date</th><th>Product</th><th>SKU</th><th>Type</th><th>Qty</th><th>Reason</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td class="text-muted small"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($m['created_at']))) ?></td>
                        <td><?= htmlspecialchars($m['product_name']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($m['sku']) ?></td>
                        <td><span class="badge-status <?= $typeBadge[$m['movement_type']] ?? 'badge-neutral' ?>"><?= str_replace('_', ' ', $m['movement_type']) ?></span></td>
                        <td class="<?= $m['quantity'] >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= $m['quantity'] >= 0 ? '+' : '' ?><?= (int) $m['quantity'] ?></td>
                        <td class="small"><?= htmlspecialchars($m['reason'] ?? '—') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($m['performed_by_name'] ?? 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('products.view');

$id = (int) ($_GET['id'] ?? 0);
$product = ProductService::find($id);
if (!$product) {
    header('Location: ' . BASE_URL . '/products/index.php');
    exit;
}
$movements = InventoryService::recentMovements($id, 15);

$badgeMap = [
    'safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger',
    'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger',
];

$pageTitle = $product['name'];
$activeNav = 'products';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <nav class="breadcrumb"><a href="<?= BASE_URL ?>/products/index.php" class="text-decoration-none">Products</a>&nbsp;/&nbsp;<?= htmlspecialchars($product['name']) ?></nav>
        <h1 class="page-title h4 mb-0"><?= htmlspecialchars($product['name']) ?></h1>
        <p class="text-muted mb-0 small">SKU: <?= htmlspecialchars($product['sku']) ?></p>
    </div>
    <?php if (can('products.manage')): ?>
        <a href="<?= BASE_URL ?>/products/edit.php?id=<?= $product['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Overview</h2>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Category</dt><dd class="col-7"><?= htmlspecialchars($product['category_name'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Brand</dt><dd class="col-7"><?= htmlspecialchars($product['brand_name'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Cost Price</dt><dd class="col-7">₱<?= number_format((float) $product['cost_price'], 2) ?></dd>
                <dt class="col-5 text-muted">Selling Price</dt><dd class="col-7">₱<?= number_format((float) $product['selling_price'], 2) ?></dd>
                <dt class="col-5 text-muted">Reorder Level</dt><dd class="col-7"><?= (int) $product['reorder_level'] ?></dd>
                <dt class="col-5 text-muted">Distributor</dt><dd class="col-7"><?= htmlspecialchars($product['distributor_name'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Lead Time</dt><dd class="col-7"><?= (int) ($product['lead_time_days'] ?? $product['distributor_lead_time'] ?? 0) ?> days</dd>
                <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge-status <?= $product['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($product['status']) ?></span></dd>
            </dl>
            <?php if ($product['description']): ?>
                <hr><p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
            <?php endif; ?>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Stock Status</h2>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">Current Stock</span>
                <span class="fs-4 fw-bold"><?= (int) $product['quantity_on_hand'] ?></span>
            </div>
            <span class="badge-status <?= $badgeMap[$product['stock_status']] ?>"><?= ucfirst($product['stock_status']) ?> stock</span>
            <?php if ($product['expiration_status']): ?>
                <div class="mt-3 d-flex align-items-center justify-content-between">
                    <span class="text-muted small">Expires</span>
                    <span><?= htmlspecialchars(date('M j, Y', strtotime($product['expiration_date']))) ?></span>
                </div>
                <span class="badge-status <?= $badgeMap[$product['expiration_status']] ?? 'badge-neutral' ?>"><?= str_replace('_', ' ', ucfirst($product['expiration_status'])) ?></span>
            <?php endif; ?>
            <?php if (can('inventory.adjust') || can('inventory.receive')): ?>
                <a href="<?= BASE_URL ?>/inventory/index.php?q=<?= urlencode($product['sku']) ?>" class="btn btn-sm btn-outline-primary w-100 mt-3">Manage Stock</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Recent Stock Movements</h2>
            <?php if (!$movements): ?>
                <div class="empty-state py-4"><i class="bi bi-arrow-left-right"></i><p class="mb-0">No stock movements recorded yet.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Reason</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td class="text-muted small"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($m['created_at']))) ?></td>
                                <td><span class="badge-status badge-neutral"><?= str_replace('_', ' ', $m['movement_type']) ?></span></td>
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
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

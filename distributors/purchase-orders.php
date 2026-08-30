<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();

$isOwnerView = can('distributors.manage');
$isDistributorView = hasRole(ROLE_DISTRIBUTOR) && can('distributors.portal');
if (!$isOwnerView && !$isDistributorView) {
    renderForbidden();
}

$filters = ['status' => $_GET['status'] ?? ''];
$distributorId = $isDistributorView ? (int) ($_SESSION['distributor_id'] ?? 0) : null;
$purchaseOrders = PurchaseOrderService::all($filters, $distributorId);

$poBadge = ['requested' => 'badge-neutral', 'confirmed' => 'badge-info', 'preparing' => 'badge-info', 'shipped' => 'badge-info', 'delivered' => 'badge-success', 'cancelled' => 'badge-danger'];

$pageTitle = 'Purchase Orders';
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Purchase Orders</h1>
        <p class="text-muted mb-0 small"><?= $isOwnerView ? 'All distributors.' : 'Orders assigned to you.' ?></p>
    </div>
    <?php if ($isOwnerView): ?>
        <a href="<?= BASE_URL ?>/distributors/po-add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Purchase Order</a>
    <?php endif; ?>
</div>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (PurchaseOrderService::STATUSES as $s): ?>
                    <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button></div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$purchaseOrders): ?>
        <div class="empty-state"><i class="bi bi-truck"></i><p class="mb-0">No purchase orders found.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>PO #</th><th>Distributor</th><th>Items</th><th>Total Cost</th><th>Expected Delivery</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($purchaseOrders as $po): ?>
                    <tr>
                        <td class="fw-semibold small"><?= htmlspecialchars($po['po_number']) ?></td>
                        <td class="small"><?= htmlspecialchars($po['distributor_name']) ?></td>
                        <td><?= (int) $po['item_count'] ?></td>
                        <td>₱<?= number_format((float) $po['total_cost'], 2) ?></td>
                        <td class="small"><?= $po['expected_delivery_date'] ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '—' ?></td>
                        <td><span class="badge-status <?= $poBadge[$po['status']] ?>"><?= ucfirst($po['status']) ?></span></td>
                        <td class="text-end"><a href="<?= BASE_URL ?>/distributors/po-view.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

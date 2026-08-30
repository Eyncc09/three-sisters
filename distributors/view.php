<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);

$isOwnerView = can('distributors.manage');
$isSelfView = hasRole(ROLE_DISTRIBUTOR) && can('distributors.portal') && (int) ($_SESSION['distributor_id'] ?? 0) === $id;
if (!$isOwnerView && !$isSelfView) {
    renderForbidden();
}

$distributor = DistributorService::find($id);
if (!$distributor) {
    header('Location: ' . BASE_URL . '/distributors/index.php');
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_lead_time') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        try {
            DistributorService::updateLeadTime($id, (int) $_POST['lead_time_days'], (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/distributors/view.php?id=' . $id . '&msg=leadtime');
            exit;
        } catch (InvalidArgumentException $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}
if (isset($_GET['msg'])) {
    $messages = ['created' => 'Distributor added.', 'updated' => 'Distributor updated.', 'leadtime' => 'Lead time updated.'];
    if (isset($messages[$_GET['msg']])) {
        $flash = ['type' => 'success', 'text' => $messages[$_GET['msg']]];
    }
}

$suppliedProducts = DistributorService::suppliedProducts($id);
$reorderInsights = DistributorService::reorderInsights($id);
$reorderByProduct = [];
foreach ($reorderInsights as $r) { $reorderByProduct[$r['product_id']] = $r; }

$purchaseOrders = PurchaseOrderService::all([], $id);

$stockBadge = ['safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];
$recBadge = ['Sufficient' => 'badge-success', 'Low Stock' => 'badge-warning', 'Reorder Recommended' => 'badge-warning', 'Urgent Reorder' => 'badge-danger'];
$poBadge = ['requested' => 'badge-neutral', 'confirmed' => 'badge-info', 'preparing' => 'badge-info', 'shipped' => 'badge-info', 'delivered' => 'badge-success', 'cancelled' => 'badge-danger'];

$pageTitle = $distributor['name'];
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <?php if ($isOwnerView): ?><nav class="breadcrumb"><a href="<?= BASE_URL ?>/distributors/index.php" class="text-decoration-none">Distributors</a>&nbsp;/&nbsp;<?= htmlspecialchars($distributor['name']) ?></nav><?php endif; ?>
        <h1 class="page-title h4 mb-0"><?= htmlspecialchars($distributor['name']) ?></h1>
        <span class="badge-status <?= $distributor['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?> mt-1 d-inline-block"><?= ucfirst($distributor['status']) ?></span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/distributors/chat.php?distributor_id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-chat-dots me-1"></i>Message <?= $isOwnerView ? 'Distributor' : 'Owner' ?></a>
        <?php if ($isOwnerView): ?>
            <a href="<?= BASE_URL ?>/distributors/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Contact Info</h2>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Contact Person</dt><dd class="col-7"><?= htmlspecialchars($distributor['contact_person'] ?: '—') ?></dd>
                <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= htmlspecialchars($distributor['phone'] ?: '—') ?></dd>
                <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= htmlspecialchars($distributor['email'] ?: '—') ?></dd>
                <dt class="col-5 text-muted">Address</dt><dd class="col-7"><?= htmlspecialchars($distributor['address'] ?: '—') ?></dd>
            </dl>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-2">Lead Time</h2>
            <p class="text-muted small">Used across Stock Analysis and reorder recommendations for products supplied by this distributor.</p>
            <form method="POST" class="d-flex gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_lead_time">
                <input type="number" min="1" name="lead_time_days" class="form-control form-control-sm" value="<?= (int) $distributor['lead_time_days'] ?>" style="max-width:100px;">
                <span class="align-self-center small text-muted">days</span>
                <button type="submit" class="btn btn-primary btn-sm ms-auto">Save</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-1">Products Supplied &amp; Reorder Insight</h2>
            <p class="text-muted small">Average daily sales measured over the last 30 days of completed sales. Estimated Stock Duration = Current Stock ÷ Average Daily Sales — a transparent calculation, not a forecast.</p>
            <?php if (!$suppliedProducts): ?>
                <div class="empty-state py-4"><i class="bi bi-box-seam"></i><p class="mb-0">No products are currently assigned to this distributor.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Product</th><th>Stock</th><th>Avg Daily Sales</th><th>Est. Duration</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($suppliedProducts as $p): ?>
                            <?php $insight = $reorderByProduct[$p['id']] ?? null; ?>
                            <tr>
                                <td><a href="<?= BASE_URL ?>/products/view.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark small"><?= htmlspecialchars($p['name']) ?></a><div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($p['sku']) ?></div></td>
                                <td><span class="badge-status <?= $stockBadge[$p['stock_status']] ?>"><?= $p['current_stock'] ?></span></td>
                                <td class="small"><?= $insight ? number_format($insight['avg_daily_sales'], 2) : '—' ?></td>
                                <td class="small"><?= $insight && $insight['estimated_days_remaining'] !== null ? $insight['estimated_days_remaining'] . 'd' : 'N/A' ?></td>
                                <td><?php if ($insight): ?><span class="badge-status <?= $recBadge[$insight['recommendation']] ?>"><?= htmlspecialchars($insight['recommendation']) ?></span><?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="ts-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Purchase Orders</h2>
                <?php if ($isOwnerView): ?>
                    <a href="<?= BASE_URL ?>/distributors/po-add.php?distributor_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New PO</a>
                <?php endif; ?>
            </div>
            <?php if (!$purchaseOrders): ?>
                <div class="empty-state py-4"><i class="bi bi-truck"></i><p class="mb-0">No purchase orders yet.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>PO #</th><th>Items</th><th>Expected Delivery</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($purchaseOrders, 0, 10) as $po): ?>
                            <tr>
                                <td class="small fw-semibold"><?= htmlspecialchars($po['po_number']) ?></td>
                                <td><?= (int) $po['item_count'] ?></td>
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
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

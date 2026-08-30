<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('promotions.manage');

$id = (int) ($_GET['id'] ?? 0);
$promo = PromotionService::find($id);
if (!$promo) {
    header('Location: ' . BASE_URL . '/promotions/index.php');
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'calculate_performance') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        try {
            PromotionService::calculatePerformance($id, (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/promotions/view.php?id=' . $id . '&msg=performance');
            exit;
        } catch (RuntimeException $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'performance') {
    $flash = ['type' => 'success', 'text' => 'Performance recalculated from actual completed orders.'];
}

$performance = PromotionService::storedPerformance($id);

$statusBadge = ['scheduled' => 'badge-info', 'active' => 'badge-success', 'inactive' => 'badge-neutral', 'ended' => 'badge-neutral'];
$stockBadge = ['safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];

$attentionCount = count(array_filter($promo['products'], fn($p) => $p['stock_status'] !== 'safe' || in_array($p['expiration_status'], ['expiring_soon', 'expired'], true)));

$pageTitle = $promo['name'];
$activeNav = 'promotions';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <nav class="breadcrumb"><a href="<?= BASE_URL ?>/promotions/index.php" class="text-decoration-none">Promotions</a>&nbsp;/&nbsp;<?= htmlspecialchars($promo['name']) ?></nav>
        <h1 class="page-title h4 mb-0"><?= htmlspecialchars($promo['name']) ?></h1>
        <span class="badge-status <?= $statusBadge[$promo['effective_status']] ?> mt-1 d-inline-block"><?= ucfirst($promo['effective_status']) ?></span>
    </div>
    <a href="<?= BASE_URL ?>/promotions/edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Promotion Summary</h2>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Discount</dt>
                <dd class="col-7"><?= $promo['discount_type'] === 'percentage' ? number_format((float) $promo['discount_value'], 0) . '% off' : '₱' . number_format((float) $promo['discount_value'], 2) . ' off/unit' ?></dd>
                <dt class="col-5 text-muted">Active Period</dt>
                <dd class="col-7"><?= htmlspecialchars(date('M j, Y', strtotime($promo['start_date']))) ?> – <?= htmlspecialchars(date('M j, Y', strtotime($promo['end_date']))) ?></dd>
                <dt class="col-5 text-muted">Products</dt>
                <dd class="col-7"><?= count($promo['products']) ?></dd>
                <dt class="col-5 text-muted">Created By</dt>
                <dd class="col-7"><?= htmlspecialchars($promo['created_by_name'] ?? '—') ?></dd>
            </dl>
            <?php if ($promo['description']): ?>
                <hr><p class="small text-muted mb-0"><?= nl2br(htmlspecialchars($promo['description'])) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($attentionCount > 0): ?>
            <div class="ts-card p-3 mb-3" style="border-color: var(--ts-warning);">
                <div class="d-flex align-items-center gap-2 text-warning-emphasis mb-1">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong class="small">Inventory Attention Needed</strong>
                </div>
                <p class="small text-muted mb-0"><?= $attentionCount ?> of the products in this promotion are low/critical on stock or approaching/past expiration — see the table for details.</p>
            </div>
        <?php endif; ?>

        <div class="ts-card p-3">
            <h2 class="h6 mb-2">Performance</h2>
            <p class="text-muted small">Recalculates Before/During/After from actual completed orders containing this promotion's products.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="calculate_performance">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i>Calculate Performance</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Products in this Promotion</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Stock</th><th>Expiration</th></tr></thead>
                    <tbody>
                    <?php foreach ($promo['products'] as $p): ?>
                        <tr>
                            <td><a href="<?= BASE_URL ?>/products/view.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($p['name']) ?></a></td>
                            <td class="small text-muted"><?= htmlspecialchars($p['sku']) ?></td>
                            <td>₱<?= number_format((float) $p['selling_price'], 2) ?></td>
                            <td><span class="badge-status <?= $stockBadge[$p['stock_status']] ?>"><?= $p['current_stock'] ?> — <?= ucfirst($p['stock_status']) ?></span></td>
                            <td>
                                <?php if ($p['expiration_status']): ?>
                                    <span class="badge-status <?= $stockBadge[$p['expiration_status']] ?>"><?= str_replace('_', ' ', ucfirst($p['expiration_status'])) ?></span>
                                <?php else: ?><span class="text-muted small">N/A</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-1">Before / During / After</h2>
            <p class="text-muted small">Sales during promotion period, compared with the same-length window immediately before it. This shows what happened — it does not claim the promotion caused any change.</p>

            <?php if (!$performance): ?>
                <div class="empty-state py-4"><i class="bi bi-graph-up"></i><p class="mb-0">No performance data yet. Click "Calculate Performance" to measure it from actual orders.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Period</th><th>Dates</th><th>Transactions</th><th>Units Sold</th><th>Revenue</th><th>Discount Given (est.)</th><th>Avg Basket</th></tr></thead>
                        <tbody>
                        <?php foreach (['before', 'during', 'after'] as $period): ?>
                            <?php if (!isset($performance[$period])) continue; ?>
                            <?php $row = $performance[$period]; ?>
                            <tr class="<?= $period === 'during' ? 'table-active' : '' ?>">
                                <td class="fw-semibold text-capitalize"><?= $period ?></td>
                                <td class="small text-muted"><?= htmlspecialchars(date('M j', strtotime($row['period_start']))) ?> – <?= htmlspecialchars(date('M j, Y', strtotime($row['period_end']))) ?></td>
                                <td><?= (int) $row['transactions_count'] ?></td>
                                <td><?= (int) $row['units_sold'] ?></td>
                                <td>₱<?= number_format((float) $row['revenue'], 2) ?></td>
                                <td>₱<?= number_format((float) $row['discount_given'], 2) ?></td>
                                <td><?= number_format((float) $row['avg_basket_size'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($performance['during']['transactions_count'] ?? 0) === 0): ?>
                    <div class="alert alert-warning py-2 mt-3 small mb-0">No transaction data available for this promotion.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

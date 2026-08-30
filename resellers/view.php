<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('resellers.manage');

$id = (int) ($_GET['id'] ?? 0);
$reseller = ResellerService::find($id);
if (!$reseller) { header('Location: ' . BASE_URL . '/resellers/index.php'); exit; }

$summary = ResellerService::purchaseSummary($id);
$favorites = ResellerService::favoriteProducts($id);

$pageTitle = $reseller['full_name'];
$activeNav = 'resellers';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/resellers/index.php" class="text-decoration-none">Resellers</a>&nbsp;/&nbsp;<?= htmlspecialchars($reseller['full_name']) ?></nav>
    <h1 class="page-title h4 mb-0"><?= htmlspecialchars($reseller['full_name']) ?></h1>
    <?php if ($reseller['business_name']): ?><p class="text-muted mb-0 small"><?= htmlspecialchars($reseller['business_name']) ?></p><?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Profile</h2>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= htmlspecialchars($reseller['phone'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= htmlspecialchars($reseller['email'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Address</dt><dd class="col-7"><?= htmlspecialchars($reseller['address'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Registered</dt><dd class="col-7"><?= htmlspecialchars(date('M j, Y', strtotime($reseller['registration_date']))) ?></dd>
                <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge-status <?= $reseller['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($reseller['status']) ?></span></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="row g-3 mb-3">
            <div class="col-4"><div class="ts-card stat-card"><div class="stat-label">Total Orders</div><div class="stat-value"><?= (int) $summary['total_orders'] ?></div></div></div>
            <div class="col-4"><div class="ts-card stat-card"><div class="stat-label">Total Purchases</div><div class="stat-value">₱<?= number_format((float) $summary['total_spent'], 2) ?></div></div></div>
            <div class="col-4"><div class="ts-card stat-card"><div class="stat-label">Last Order</div><div class="stat-value" style="font-size:1rem;"><?= $summary['last_order'] ? htmlspecialchars(date('M j, Y', strtotime($summary['last_order']))) : '—' ?></div></div></div>
        </div>
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Most Purchased Products</h2>
            <?php if (!$favorites): ?>
                <div class="empty-state py-4"><i class="bi bi-box-seam"></i><p class="mb-0">No order history yet — this will populate once POS/orders are live.</p></div>
            <?php else: ?>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach ($favorites as $f): ?>
                        <li class="d-flex justify-content-between border-bottom py-2"><span><?= htmlspecialchars($f['name']) ?></span><span class="text-muted"><?= (int) $f['units'] ?> units</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('customers.manage') && !can('customers.view')) { renderForbidden(); }

$id = (int) ($_GET['id'] ?? 0);
$customer = CustomerService::find($id);
if (!$customer) { header('Location: ' . BASE_URL . '/customers/index.php'); exit; }

$summary = CustomerService::purchaseSummary($id);
$favorites = CustomerService::favoriteProducts($id);

$pageTitle = $customer['full_name'];
$activeNav = 'customers';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/customers/index.php" class="text-decoration-none">Customers</a>&nbsp;/&nbsp;<?= htmlspecialchars($customer['full_name']) ?></nav>
    <h1 class="page-title h4 mb-0"><?= htmlspecialchars($customer['full_name']) ?></h1>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Contact Info</h2>
            <dl class="row small mb-0">
                <dt class="col-4 text-muted">Phone</dt><dd class="col-8"><?= htmlspecialchars($customer['phone'] ?? '—') ?></dd>
                <dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= htmlspecialchars($customer['email'] ?? '—') ?></dd>
                <dt class="col-4 text-muted">Address</dt><dd class="col-8"><?= htmlspecialchars($customer['address'] ?? '—') ?></dd>
                <dt class="col-4 text-muted">Customer Since</dt><dd class="col-8"><?= htmlspecialchars(date('M j, Y', strtotime($customer['created_at']))) ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="row g-3 mb-3">
            <div class="col-4">
                <div class="ts-card stat-card"><div class="stat-label">Total Orders</div><div class="stat-value"><?= (int) $summary['total_orders'] ?></div></div>
            </div>
            <div class="col-4">
                <div class="ts-card stat-card"><div class="stat-label">Total Spent</div><div class="stat-value">₱<?= number_format((float) $summary['total_spent'], 2) ?></div></div>
            </div>
            <div class="col-4">
                <div class="ts-card stat-card"><div class="stat-label">Last Purchase</div><div class="stat-value" style="font-size:1rem;"><?= $summary['last_purchase'] ? htmlspecialchars(date('M j, Y', strtotime($summary['last_purchase']))) : '—' ?></div></div>
            </div>
        </div>
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Favorite Products</h2>
            <?php if (!$favorites): ?>
                <div class="empty-state py-4"><i class="bi bi-heart"></i><p class="mb-0">No purchase history yet — this will populate once POS is live.</p></div>
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

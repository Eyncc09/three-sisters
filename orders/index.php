<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('orders.manage') && !can('orders.view_assigned')) { renderForbidden(); }

$restrictToCashier = can('orders.manage') ? null : (int) $_SESSION['user_id'];

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'customer_type' => $_GET['customer_type'] ?? '',
    'payment_method' => $_GET['payment_method'] ?? '',
    'payment_status' => $_GET['payment_status'] ?? '',
    'order_status' => $_GET['order_status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = OrderService::transactionHistory($filters, $page, $restrictToCashier);

$pageTitle = 'Transaction History';
$activeNav = 'orders';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Transaction History</h1>
        <p class="text-muted mb-0 small"><?= $restrictToCashier ? 'Your completed sales.' : 'All orders across the business.' ?></p>
    </div>
    <?php if (can('payments.verify')): ?>
        <a href="<?= BASE_URL ?>/payments/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-check me-1"></i>Payment Verification</a>
    <?php endif; ?>
</div>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Order/Sale #, name" value="<?= htmlspecialchars($filters['q']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Customer Type</label>
            <select name="customer_type" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="retail" <?= $filters['customer_type'] === 'retail' ? 'selected' : '' ?>>Retail</option>
                <option value="reseller" <?= $filters['customer_type'] === 'reseller' ? 'selected' : '' ?>>Reseller</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Payment</label>
            <select name="payment_method" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="cash" <?= $filters['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="gcash" <?= $filters['payment_method'] === 'gcash' ? 'selected' : '' ?>>GCash</option>
                <option value="bank_transfer" <?= $filters['payment_method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Payment Status</label>
            <select name="payment_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="pending" <?= $filters['payment_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="verified" <?= $filters['payment_status'] === 'verified' ? 'selected' : '' ?>>Verified</option>
                <option value="rejected" <?= $filters['payment_status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label small">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from']) ?>">
        </div>
        <div class="col-md-1">
            <label class="form-label small">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to']) ?>">
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$result['items']): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><p class="mb-0">No transactions match your filters.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Sale #</th><th>Order #</th><th>Date</th><th>Customer</th><th>Type</th><th>Cashier</th><th>Total</th><th>Payment</th><th>Order Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($result['items'] as $t): ?>
                    <tr>
                        <td class="small fw-semibold"><?= htmlspecialchars($t['sale_number']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($t['order_number']) ?></td>
                        <td class="small"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($t['created_at']))) ?></td>
                        <td class="small"><?= htmlspecialchars($t['customer_name'] ?? $t['reseller_name'] ?? 'Walk-in') ?></td>
                        <td><span class="badge-status badge-neutral"><?= ucfirst($t['customer_type']) ?></span></td>
                        <td class="small"><?= htmlspecialchars($t['cashier_name']) ?></td>
                        <td>₱<?= number_format((float) $t['total_amount'], 2) ?></td>
                        <td>
                            <div class="small"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($t['payment_method'] ?? '—'))) ?></div>
                            <span class="badge-status <?= $t['payment_status'] === 'verified' ? 'badge-success' : ($t['payment_status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>"><?= ucfirst($t['payment_status'] ?? '—') ?></span>
                        </td>
                        <td><span class="badge-status badge-info"><?= ucfirst($t['order_status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/orders/view.php?id=<?= $t['order_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            <a href="<?= BASE_URL ?>/pos/receipt.php?sale_id=<?= $t['sale_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt-cutoff"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($result['pages'] > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
            <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
<?php require __DIR__ . '/../components/footer.php'; ?>

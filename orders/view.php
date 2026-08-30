<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('orders.manage') && !can('orders.view_assigned')) { renderForbidden(); }

$id = (int) ($_GET['id'] ?? 0);
$order = OrderService::findOrderDetail($id);
if (!$order) { header('Location: ' . BASE_URL . '/orders/index.php'); exit; }

// Staff may only view their own order (matched via the linked sale's cashier).
if (!can('orders.manage') && (int) ($order['cashier_id'] ?? 0) !== (int) $_SESSION['user_id']) {
    renderForbidden();
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('orders.manage')) {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        try {
            OrderService::updateStatus($id, $_POST['status'], (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/orders/view.php?id=' . $id . '&msg=status');
            exit;
        } catch (InvalidArgumentException $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'status') {
    $flash = ['type' => 'success', 'text' => 'Order status updated.'];
}

$statuses = ['pending', 'confirmed', 'preparing', 'ready', 'shipped', 'completed', 'cancelled', 'returned'];
$statusBadge = [
    'pending' => 'badge-warning', 'confirmed' => 'badge-info', 'preparing' => 'badge-info', 'ready' => 'badge-info',
    'shipped' => 'badge-info', 'completed' => 'badge-success', 'cancelled' => 'badge-danger', 'returned' => 'badge-danger',
];

$pageTitle = 'Order ' . $order['order_number'];
$activeNav = 'orders';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <nav class="breadcrumb"><a href="<?= BASE_URL ?>/orders/index.php" class="text-decoration-none">Transaction History</a>&nbsp;/&nbsp;<?= htmlspecialchars($order['order_number']) ?></nav>
        <h1 class="page-title h4 mb-0">Order <?= htmlspecialchars($order['order_number']) ?></h1>
    </div>
    <?php if ($order['sale_id']): ?>
        <a href="<?= BASE_URL ?>/pos/receipt.php?sale_id=<?= $order['sale_id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-receipt-cutoff me-1"></i>View Receipt</a>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Order Info</h2>
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Channel</dt><dd class="col-7"><?= htmlspecialchars(ucfirst($order['channel'])) ?></dd>
                <dt class="col-5 text-muted">Customer Type</dt><dd class="col-7"><?= htmlspecialchars(ucfirst($order['customer_type'] ?? '—')) ?></dd>
                <dt class="col-5 text-muted"><?= $order['customer_name'] ? 'Customer' : 'Reseller' ?></dt>
                <dd class="col-7"><?= htmlspecialchars($order['customer_name'] ?? $order['reseller_name'] ?? 'Walk-in') ?></dd>
                <dt class="col-5 text-muted">Cashier</dt><dd class="col-7"><?= htmlspecialchars($order['cashier_name'] ?? '—') ?></dd>
                <dt class="col-5 text-muted">Date</dt><dd class="col-7"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($order['created_at']))) ?></dd>
                <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge-status <?= $statusBadge[$order['status']] ?>"><?= ucfirst($order['status']) ?></span></dd>
            </dl>

            <?php if (can('orders.manage')): ?>
                <hr>
                <form method="POST">
                    <?= csrfField() ?>
                    <label class="form-label small">Change Status</label>
                    <div class="d-flex gap-2">
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Payment</h2>
            <?php foreach ($order['payments'] as $p): ?>
                <div class="mb-2 pb-2 border-bottom">
                    <div class="d-flex justify-content-between small"><span class="text-muted">Method</span><span><?= htmlspecialchars(str_replace('_', ' ', ucfirst($p['payment_method']))) ?></span></div>
                    <div class="d-flex justify-content-between small"><span class="text-muted">Amount</span><span>₱<?= number_format((float) $p['amount'], 2) ?></span></div>
                    <?php if ($p['reference_number']): ?>
                        <div class="d-flex justify-content-between small"><span class="text-muted">Reference</span><span><?= htmlspecialchars($p['reference_number']) ?></span></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between small align-items-center">
                        <span class="text-muted">Status</span>
                        <span class="badge-status <?= $p['status'] === 'verified' ? 'badge-success' : ($p['status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>"><?= ucfirst($p['status']) ?></span>
                    </div>
                    <?php if ($p['status'] === 'pending' && can('payments.verify')): ?>
                        <a href="<?= BASE_URL ?>/payments/index.php" class="small">Go to Payment Verification →</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Items</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($order['items'] as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['name']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($item['sku']) ?></td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td>₱<?= number_format((float) $item['unit_price'], 2) ?></td>
                            <td>₱<?= number_format((float) $item['line_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-end text-muted small">Subtotal</td><td>₱<?= number_format((float) $order['subtotal'], 2) ?></td></tr>
                        <tr><td colspan="4" class="text-end text-muted small">Discount</td><td>−₱<?= number_format((float) $order['discount_amount'], 2) ?></td></tr>
                        <tr><td colspan="4" class="text-end fw-bold">Total</td><td class="fw-bold">₱<?= number_format((float) $order['total_amount'], 2) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

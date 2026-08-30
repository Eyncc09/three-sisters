<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('pos.use');

$saleId = (int) ($_GET['sale_id'] ?? 0);
$sale = OrderService::getReceiptData($saleId);
if (!$sale) {
    header('Location: ' . BASE_URL . '/pos/index.php');
    exit;
}

// Staff may only view their own receipts unless they also hold orders.manage (owner).
if (!can('orders.manage') && (int) $sale['cashier_id'] !== (int) $_SESSION['user_id']) {
    renderForbidden();
}

$fresh = isset($_GET['fresh']);

$pageTitle = 'Receipt ' . $sale['sale_number'];
$activeNav = 'pos';
require __DIR__ . '/../components/header.php';
?>
<style>
    @media print {
        .app-sidebar, .app-topbar, .no-print { display: none !important; }
        .app-main { margin-left: 0 !important; }
        .app-content { padding: 0 !important; }
        .receipt-box { box-shadow: none !important; border: none !important; }
    }
    .receipt-box { max-width: 420px; margin: 0 auto; font-family: 'Inter', monospace; font-size: 0.85rem; }
    .receipt-box .divider { border-top: 1px dashed var(--ts-border); margin: 0.6rem 0; }
    .receipt-line { display: flex; justify-content: space-between; gap: 0.5rem; }
</style>

<?php if ($fresh): ?>
    <div class="alert alert-success alert-dismissible fade show py-2 no-print" role="alert">
        <i class="bi bi-check-circle me-1"></i>Transaction completed successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>New Sale</a>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Receipt</button>
        <button class="btn btn-primary btn-sm" id="downloadJpegBtn"><i class="bi bi-download me-1"></i>Save JPEG</button>
    </div>
</div>

<div class="ts-card receipt-box p-4" id="receiptCapture">
    <div class="text-center mb-2">
        <div class="fw-bold" style="font-size:1.1rem;"><?= htmlspecialchars(APP_NAME) ?></div>
        <div class="text-muted" style="font-size:0.75rem;">Beauty Products Management</div>
    </div>
    <div class="divider"></div>

    <div class="receipt-line"><span>Sale #</span><span class="fw-semibold"><?= htmlspecialchars($sale['sale_number']) ?></span></div>
    <div class="receipt-line"><span>Order #</span><span><?= htmlspecialchars($sale['order_number']) ?></span></div>
    <div class="receipt-line"><span>Date</span><span><?= htmlspecialchars(date('M j, Y g:ia', strtotime($sale['created_at']))) ?></span></div>
    <div class="receipt-line"><span>Channel</span><span><?= htmlspecialchars(ucfirst($sale['channel'])) ?></span></div>
    <div class="receipt-line"><span>Customer Type</span><span><?= htmlspecialchars(ucfirst($sale['customer_type'])) ?></span></div>
    <?php if ($sale['customer_name']): ?>
        <div class="receipt-line"><span>Customer</span><span><?= htmlspecialchars($sale['customer_name']) ?></span></div>
    <?php elseif ($sale['reseller_name']): ?>
        <div class="receipt-line"><span>Reseller</span><span><?= htmlspecialchars($sale['reseller_name']) ?><?= $sale['reseller_business'] ? ' (' . htmlspecialchars($sale['reseller_business']) . ')' : '' ?></span></div>
    <?php else: ?>
        <div class="receipt-line"><span>Customer</span><span>Walk-in</span></div>
    <?php endif; ?>
    <div class="receipt-line"><span>Cashier</span><span><?= htmlspecialchars($sale['cashier_name']) ?></span></div>

    <div class="divider"></div>

    <?php foreach ($sale['items'] as $item): ?>
        <div class="receipt-line">
            <span><?= htmlspecialchars($item['name']) ?> <span class="text-muted">×<?= (int) $item['quantity'] ?></span></span>
            <span>₱<?= number_format((float) $item['line_total'], 2) ?></span>
        </div>
        <div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($item['sku']) ?> — ₱<?= number_format((float) $item['unit_price'], 2) ?> each</div>
    <?php endforeach; ?>

    <div class="divider"></div>

    <div class="receipt-line"><span>Subtotal</span><span>₱<?= number_format((float) $sale['subtotal'], 2) ?></span></div>
    <div class="receipt-line"><span>Discount</span><span>−₱<?= number_format((float) $sale['discount_amount'], 2) ?></span></div>
    <div class="receipt-line fw-bold fs-6"><span>Grand Total</span><span>₱<?= number_format((float) $sale['total_amount'], 2) ?></span></div>

    <div class="divider"></div>

    <div class="receipt-line"><span>Payment Method</span><span><?= htmlspecialchars(str_replace('_', ' ', ucfirst($sale['payment_method']))) ?></span></div>
    <?php if ($sale['reference_number']): ?>
        <div class="receipt-line"><span>Reference #</span><span><?= htmlspecialchars($sale['reference_number']) ?></span></div>
    <?php endif; ?>
    <div class="receipt-line">
        <span>Payment Status</span>
        <span class="badge-status <?= $sale['payment_status'] === 'verified' ? 'badge-success' : ($sale['payment_status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>"><?= ucfirst($sale['payment_status']) ?></span>
    </div>

    <div class="divider"></div>
    <div class="text-center text-muted" style="font-size:0.72rem;">Thank you for shopping with us!</div>
</div>

<script src="<?= vendorOrCdn('html2canvas/html2canvas.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js') ?>"></script>
<script>
document.getElementById('downloadJpegBtn').addEventListener('click', function () {
    const target = document.getElementById('receiptCapture');
    html2canvas(target, { backgroundColor: '#ffffff', scale: 2 }).then(canvas => {
        const link = document.createElement('a');
        link.download = 'receipt-<?= htmlspecialchars($sale['sale_number']) ?>.jpg';
        link.href = canvas.toDataURL('image/jpeg', 0.92);
        link.click();
    });
});
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

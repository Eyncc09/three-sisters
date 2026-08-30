<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('payments.verify');

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'verify') {
            PaymentService::verify($paymentId, (int) $_SESSION['user_id']);
            $flash = ['type' => 'success', 'text' => 'Payment verified.'];
        } elseif ($action === 'reject') {
            PaymentService::reject($paymentId, (int) $_SESSION['user_id'], trim($_POST['reason'] ?? ''));
            $flash = ['type' => 'success', 'text' => 'Payment rejected.'];
        }
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = PaymentService::pendingPayments($page);

$pageTitle = 'Payment Verification';
$activeNav = 'orders';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Payment Verification</h1>
    <p class="text-muted mb-0 small">Pending GCash and Bank Transfer payments awaiting confirmation.</p>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card">
    <?php if (!$result['items']): ?>
        <div class="empty-state"><i class="bi bi-check2-circle"></i><p class="mb-0">No pending payments. All caught up!</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Order #</th><th>Customer</th><th>Method</th><th>Amount</th><th>Reference</th><th>Proof</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($result['items'] as $p): ?>
                    <tr>
                        <td class="fw-semibold small"><a href="<?= BASE_URL ?>/orders/view.php?id=<?= $p['order_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($p['order_number']) ?></a></td>
                        <td class="small"><?= htmlspecialchars($p['customer_name'] ?? $p['reseller_name'] ?? 'Walk-in') ?></td>
                        <td><span class="badge-status badge-neutral"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($p['payment_method']))) ?></span></td>
                        <td>₱<?= number_format((float) $p['amount'], 2) ?></td>
                        <td class="small"><?= htmlspecialchars($p['reference_number'] ?? '—') ?></td>
                        <td>
                            <?php if ($p['proof_count'] > 0): ?>
                                <a href="<?= BASE_URL ?>/payments/proof.php?payment_id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i> View</a>
                            <?php else: ?>
                                <span class="text-muted small">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars(date('M j, g:ia', strtotime($p['created_at']))) ?></td>
                        <td class="text-end">
                            <form method="POST" class="d-inline" onsubmit="return confirm('Verify this payment?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal" onclick="document.getElementById('reject_payment_id').value='<?= $p['id'] ?>'">
                                <i class="bi bi-x-lg"></i>
                            </button>
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
            <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="payment_id" id="reject_payment_id">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" placeholder="e.g. Reference number does not match"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

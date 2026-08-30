<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$po = PurchaseOrderService::find($id);
if (!$po) {
    header('Location: ' . BASE_URL . '/distributors/purchase-orders.php');
    exit;
}

$isOwnerView = can('distributors.manage');
$isDistributorView = hasRole(ROLE_DISTRIBUTOR) && can('distributors.portal') && (int) ($_SESSION['distributor_id'] ?? 0) === (int) $po['distributor_id'];
if (!$isOwnerView && !$isDistributorView) {
    renderForbidden();
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'update_status') {
                // Owner may cancel at any point; Distributor drives the fulfillment steps.
                // Kept simple/permissive per spec ("rule-based and transparent", not a strict
                // state machine) — both roles may set any valid status on THIS PO, since it's
                // already scoped to either "you manage the business" or "this is assigned to you".
                PurchaseOrderService::updateStatus($id, $_POST['status'], (int) $_SESSION['user_id'], trim($_POST['note'] ?? '') ?: null);
                header('Location: ' . BASE_URL . '/distributors/po-view.php?id=' . $id . '&msg=status');
                exit;
            } elseif ($action === 'schedule_update') {
                $note = trim($_POST['note'] ?? '');
                if ($note === '') {
                    $flash = ['type' => 'danger', 'text' => 'Enter a note describing the update.'];
                } else {
                    PurchaseOrderService::postScheduleUpdate($id, trim($_POST['expected_delivery_date'] ?? '') ?: null, $note, (int) $_SESSION['user_id']);
                    header('Location: ' . BASE_URL . '/distributors/po-view.php?id=' . $id . '&msg=schedule');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}
if (isset($_GET['msg'])) {
    $messages = ['created' => 'Purchase order created.', 'status' => 'Status updated.', 'schedule' => 'Schedule update posted.'];
    if (isset($messages[$_GET['msg']])) $flash = ['type' => 'success', 'text' => $messages[$_GET['msg']]];
}

$poBadge = ['requested' => 'badge-neutral', 'confirmed' => 'badge-info', 'preparing' => 'badge-info', 'shipped' => 'badge-info', 'delivered' => 'badge-success', 'cancelled' => 'badge-danger'];

$pageTitle = $po['po_number'];
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <nav class="breadcrumb"><a href="<?= BASE_URL ?>/distributors/purchase-orders.php" class="text-decoration-none">Purchase Orders</a>&nbsp;/&nbsp;<?= htmlspecialchars($po['po_number']) ?></nav>
        <h1 class="page-title h4 mb-0"><?= htmlspecialchars($po['po_number']) ?></h1>
        <span class="badge-status <?= $poBadge[$po['status']] ?> mt-1 d-inline-block"><?= ucfirst($po['status']) ?></span>
    </div>
    <a href="<?= BASE_URL ?>/distributors/view.php?id=<?= $po['distributor_id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-truck me-1"></i><?= htmlspecialchars($po['distributor_name']) ?></a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Order Summary</h2>
            <dl class="row small mb-0">
                <dt class="col-6 text-muted">Distributor</dt><dd class="col-6"><?= htmlspecialchars($po['distributor_name']) ?></dd>
                <dt class="col-6 text-muted">Lead Time</dt><dd class="col-6"><?= (int) $po['lead_time_days'] ?> days</dd>
                <dt class="col-6 text-muted">Expected Delivery</dt><dd class="col-6"><?= $po['expected_delivery_date'] ? htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery_date']))) : '—' ?></dd>
                <dt class="col-6 text-muted">Total Cost</dt><dd class="col-6">₱<?= number_format((float) $po['total_cost'], 2) ?></dd>
                <dt class="col-6 text-muted">Created By</dt><dd class="col-6"><?= htmlspecialchars($po['created_by_name'] ?? '—') ?></dd>
                <dt class="col-6 text-muted">Created</dt><dd class="col-6"><?= htmlspecialchars(date('M j, Y', strtotime($po['created_at']))) ?></dd>
            </dl>
        </div>

        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-2">Update Status</h2>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_status">
                <select name="status" class="form-select form-select-sm mb-2">
                    <?php foreach (PurchaseOrderService::STATUSES as $s): ?>
                        <option value="<?= $s ?>" <?= $po['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="note" class="form-control form-control-sm mb-2" placeholder="Optional note">
                <button type="submit" class="btn btn-primary btn-sm w-100">Update</button>
            </form>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-2">Schedule / Delivery Update</h2>
            <p class="text-muted small">Update the expected delivery date and/or leave a note — visible to both sides without changing the order status.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="schedule_update">
                <label class="form-label small">New Expected Delivery Date</label>
                <input type="date" name="expected_delivery_date" class="form-control form-control-sm mb-2" value="<?= htmlspecialchars($po['expected_delivery_date'] ?? '') ?>">
                <label class="form-label small">Note <span class="text-danger">*</span></label>
                <textarea name="note" class="form-control form-control-sm mb-2" rows="2" placeholder="e.g. Delayed due to supplier backlog" required></textarea>
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Post Update</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-3">Items</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Product</th><th>SKU</th><th>Quantity</th><th>Unit Cost</th><th>Line Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($po['items'] as $item): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($item['name']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($item['sku']) ?></td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td>₱<?= number_format((float) $item['unit_cost'], 2) ?></td>
                            <td>₱<?= number_format((float) $item['unit_cost'] * (int) $item['quantity'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Delivery / Status History</h2>
            <?php if (!$po['updates']): ?>
                <div class="empty-state py-3"><i class="bi bi-clock-history"></i><p class="mb-0 small">No updates yet.</p></div>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($po['updates'] as $u): ?>
                        <li class="d-flex gap-3 py-2 border-bottom">
                            <div class="text-muted small" style="min-width:110px;"><?= htmlspecialchars(date('M j, g:ia', strtotime($u['created_at']))) ?></div>
                            <div>
                                <span class="badge-status badge-neutral"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($u['status']))) ?></span>
                                <?php if ($u['note']): ?><div class="small mt-1"><?= htmlspecialchars($u['note']) ?></div><?php endif; ?>
                                <div class="text-muted" style="font-size:0.7rem;">by <?= htmlspecialchars($u['updated_by_name'] ?? 'System') ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

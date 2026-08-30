<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('inventory.adjust') && !can('inventory.receive')) { renderForbidden(); }

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $productId = (int) ($_POST['product_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {
            if ($action === 'stock_in' && (can('inventory.adjust') || can('inventory.receive'))) {
                $qty = (int) ($_POST['quantity'] ?? 0);
                if ($qty <= 0) {
                    $flash = ['type' => 'danger', 'text' => 'Enter a quantity greater than zero.'];
                } elseif ($reason === '') {
                    $flash = ['type' => 'danger', 'text' => 'Please provide a reason/reference for this stock-in.'];
                } else {
                    InventoryService::stockIn($productId, $qty, $reason, (int) $_SESSION['user_id']);
                    $flash = ['type' => 'success', 'text' => 'Stock-in recorded successfully.'];
                }
            } elseif ($action === 'adjust' && can('inventory.adjust')) {
                $qty = (int) ($_POST['quantity'] ?? 0);
                if ($qty === 0) {
                    $flash = ['type' => 'danger', 'text' => 'Enter a non-zero adjustment quantity (use negative for deductions).'];
                } elseif ($reason === '') {
                    $flash = ['type' => 'danger', 'text' => 'Please provide a reason for this adjustment.'];
                } else {
                    InventoryService::adjust($productId, $qty, $reason, (int) $_SESSION['user_id']);
                    $flash = ['type' => 'success', 'text' => 'Inventory adjustment recorded.'];
                }
            } else {
                renderForbidden();
            }
        } catch (RuntimeException $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}

$filters = ['q' => trim($_GET['q'] ?? ''), 'stock_status' => $_GET['stock_status'] ?? ''];
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = ProductService::search(array_merge($filters, ['status' => 'active']), $page);

$badgeMap = ['safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];

$pageTitle = 'Inventory';
$activeNav = 'inventory';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Inventory</h1>
        <p class="text-muted mb-0 small">Track stock levels and record movements.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/inventory/expiration.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-hourglass-split me-1"></i>Expiration Monitoring</a>
        <a href="<?= BASE_URL ?>/inventory/movements.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Movement History</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Name or SKU" value="<?= htmlspecialchars($filters['q']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Stock Status</label>
            <select name="stock_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="safe" <?= $filters['stock_status'] === 'safe' ? 'selected' : '' ?>>Safe</option>
                <option value="low" <?= $filters['stock_status'] === 'low' ? 'selected' : '' ?>>Low</option>
                <option value="critical" <?= $filters['stock_status'] === 'critical' ? 'selected' : '' ?>>Critical</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$result['items']): ?>
        <div class="empty-state"><i class="bi bi-clipboard-data"></i><p class="mb-0">No products found.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Stock</th><th>Reorder Lvl</th><th>Expiration</th><th>Distributor</th><th>Lead Time</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($result['items'] as $p): ?>
                    <tr>
                        <td class="text-muted small"><?= htmlspecialchars($p['sku']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                        <td>
                            <?= (int) $p['quantity_on_hand'] ?>
                            <span class="badge-status <?= $badgeMap[$p['stock_status']] ?>"><?= ucfirst($p['stock_status']) ?></span>
                        </td>
                        <td><?= (int) $p['reorder_level'] ?></td>
                        <td>
                            <?php if ($p['expiration_status']): ?>
                                <span class="badge-status <?= $badgeMap[$p['expiration_status']] ?? 'badge-neutral' ?>"><?= str_replace('_', ' ', ucfirst($p['expiration_status'])) ?></span>
                            <?php else: ?><span class="text-muted small">N/A</span><?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($p['distributor_name'] ?? '—') ?></td>
                        <td class="small"><?= (int) ($p['lead_time_days'] ?? 0) ?: '—' ?> <?= $p['lead_time_days'] ? 'days' : '' ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#stockInModal"
                                    onclick="openStockIn(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                                <i class="bi bi-box-arrow-in-down"></i>
                            </button>
                            <?php if (can('inventory.adjust')): ?>
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#adjustModal"
                                        onclick="openAdjust(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-sliders"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Stock In Modal -->
<div class="modal fade" id="stockInModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="stock_in">
                <input type="hidden" name="product_id" id="stockin_product_id">
                <div class="modal-header">
                    <h5 class="modal-title">Stock In — <span id="stockin_product_name"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Quantity Received <span class="text-danger">*</span></label>
                        <input type="number" min="1" name="quantity" class="form-control" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Reason / Reference <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. PO-2026-00125 delivery" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Stock In</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (can('inventory.adjust')): ?>
<!-- Manual Adjustment Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="product_id" id="adjust_product_id">
                <div class="modal-header">
                    <h5 class="modal-title">Manual Adjustment — <span id="adjust_product_name"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Quantity Change <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" placeholder="e.g. -3 for damaged/lost items" required>
                        <div class="form-text">Use a negative number to decrease stock (damaged, expired, lost), positive to increase (correction).</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. Damaged during transport" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openStockIn(id, name) {
    document.getElementById('stockin_product_id').value = id;
    document.getElementById('stockin_product_name').textContent = name;
}
function openAdjust(id, name) {
    document.getElementById('adjust_product_id').value = id;
    document.getElementById('adjust_product_name').textContent = name;
}
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('distributors.manage');

$preselectedDistributorId = (int) ($_GET['distributor_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $errors['_general'] = 'Your session expired. Please resubmit the form.';
    } else {
        $distributorId = (int) ($_POST['distributor_id'] ?? 0);
        $expectedDelivery = trim($_POST['expected_delivery_date'] ?? '');
        if ($expectedDelivery !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $expectedDelivery);
            if (!$d || $d->format('Y-m-d') !== $expectedDelivery) {
                $errors['expected_delivery_date'] = 'Enter a valid expected delivery date.';
                $expectedDelivery = '';
            }
        }
        $productIds = $_POST['product_ids'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $costs = $_POST['unit_costs'] ?? [];

        if (!$distributorId || !DistributorService::find($distributorId)) {
            $errors['distributor_id'] = 'Select a valid distributor.';
        }

        $items = [];
        foreach ($productIds as $i => $pid) {
            $qty = (int) ($quantities[$i] ?? 0);
            $cost = (float) ($costs[$i] ?? 0);
            if ((int) $pid > 0 && $qty > 0) {
                $items[] = ['product_id' => (int) $pid, 'quantity' => $qty, 'unit_cost' => max(0, $cost)];
            }
        }
        if (!$items) {
            $errors['items'] = 'Add at least one product with a quantity greater than zero.';
        }

        if (!$errors) {
            try {
                $id = PurchaseOrderService::create($distributorId, $items, $expectedDelivery ?: null, (int) $_SESSION['user_id']);
                header('Location: ' . BASE_URL . '/distributors/po-view.php?id=' . $id . '&msg=created');
                exit;
            } catch (RuntimeException | InvalidArgumentException $e) {
                $errors['items'] = $e->getMessage();
            }
        }
    }
}

$distributors = DistributorService::all(['status' => 'active']);
$products = db()->query(
    "SELECT p.id, p.sku, p.name, p.cost_price, p.primary_distributor_id, COALESCE(i.quantity_on_hand,0) AS current_stock
     FROM products p LEFT JOIN inventory i ON i.product_id = p.id
     WHERE p.status = 'active' ORDER BY p.name ASC"
)->fetchAll();

$pageTitle = 'New Purchase Order';
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/distributors/purchase-orders.php" class="text-decoration-none">Purchase Orders</a>&nbsp;/&nbsp;New</nav>
    <h1 class="page-title h4 mb-0">New Purchase Order</h1>
</div>

<?php if (isset($errors['_general'])): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($errors['_general']) ?></div><?php endif; ?>
<?php if (isset($errors['distributor_id'])): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($errors['distributor_id']) ?></div><?php endif; ?>
<?php if (isset($errors['expected_delivery_date'])): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($errors['expected_delivery_date']) ?></div><?php endif; ?>
<?php if (isset($errors['items'])): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($errors['items']) ?></div><?php endif; ?>

<div class="ts-card p-4">
    <form method="POST">
        <?= csrfField() ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">Distributor <span class="text-danger">*</span></label>
                <select name="distributor_id" id="distributorSelect" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($distributors as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $preselectedDistributorId === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?> (lead time <?= (int) $d['lead_time_days'] ?>d)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Expected Delivery Date</label>
                <input type="date" name="expected_delivery_date" class="form-control" value="<?= htmlspecialchars($_POST['expected_delivery_date'] ?? '') ?>">
            </div>
        </div>

        <label class="form-label">Products <span class="text-danger">*</span></label>
        <div class="table-responsive" style="max-height:380px; overflow-y:auto; border:1px solid var(--ts-border); border-radius:var(--ts-radius);">
            <table class="table table-sm align-middle mb-0">
                <thead class="sticky-top bg-white"><tr><th></th><th>SKU</th><th>Product</th><th>Current Stock</th><th>Qty</th><th>Unit Cost (₱)</th></tr></thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr data-distributor="<?= (int) ($p['primary_distributor_id'] ?? 0) ?>">
                        <td><input type="checkbox" class="form-check-input product-check" onchange="toggleRow(this)"></td>
                        <td class="small text-muted"><?= htmlspecialchars($p['sku']) ?></td>
                        <td class="small"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="small"><?= (int) $p['current_stock'] ?></td>
                        <td><input type="number" name="quantities[]" min="0" class="form-control form-control-sm qty-input" style="width:80px;" disabled></td>
                        <td>
                            <input type="hidden" name="product_ids[]" class="pid-input" value="<?= $p['id'] ?>" disabled>
                            <input type="number" name="unit_costs[]" step="0.01" min="0" class="form-control form-control-sm cost-input" style="width:100px;" value="<?= number_format((float) $p['cost_price'], 2, '.', '') ?>" disabled>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-text">Tip: selecting a distributor above highlights the products it usually supplies.</div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create Purchase Order</button>
            <a href="<?= BASE_URL ?>/distributors/purchase-orders.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
function toggleRow(checkbox) {
    const row = checkbox.closest('tr');
    const enable = checkbox.checked;
    row.querySelectorAll('.qty-input, .cost-input, .pid-input').forEach(el => el.disabled = !enable);
    if (enable) row.querySelector('.qty-input').focus();
}
function highlightDistributorRows() {
    const selected = document.getElementById('distributorSelect').value;
    document.querySelectorAll('tbody tr').forEach(row => {
        row.classList.toggle('table-active', selected && row.dataset.distributor === selected);
    });
}
document.getElementById('distributorSelect').addEventListener('change', highlightDistributorRows);
highlightDistributorRows();
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

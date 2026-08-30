<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('inventory.adjust') && !can('inventory.receive') && !can('products.manage')) { renderForbidden(); }

$categoryId = $_GET['category_id'] ?? '';
$status = $_GET['status'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = ["p.status = 'active'", 'p.expiration_date IS NOT NULL'];
$params = [];
if ($categoryId) { $where[] = 'p.category_id = :cat'; $params['cat'] = $categoryId; }
if ($from) { $where[] = 'p.expiration_date >= :from'; $params['from'] = $from; }
if ($to) { $where[] = 'p.expiration_date <= :to'; $params['to'] = $to; }

$sql = "SELECT p.*, c.name AS category_name, COALESCE(i.quantity_on_hand,0) AS quantity_on_hand
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.expiration_date ASC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['expiration_status'] = InventoryService::expirationStatus($r['expiration_date']);
}
unset($r);

if ($status) {
    $rows = array_values(array_filter($rows, fn($r) => $r['expiration_status'] === $status));
}

$categories = CategoryService::all(true);
$badgeMap = ['safe' => 'badge-success', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];

$pageTitle = 'Expiration Monitoring';
$activeNav = 'inventory';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/inventory/index.php" class="text-decoration-none">Inventory</a>&nbsp;/&nbsp;Expiration Monitoring</nav>
    <h1 class="page-title h4 mb-0">Expiration Monitoring</h1>
</div>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $categoryId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="safe" <?= $status === 'safe' ? 'selected' : '' ?>>Safe</option>
                <option value="expiring_soon" <?= $status === 'expiring_soon' ? 'selected' : '' ?>>Expiring Soon</option>
                <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Filter</button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$rows): ?>
        <div class="empty-state"><i class="bi bi-hourglass-split"></i><p class="mb-0">No products match these filters.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Stock</th><th>Expiration Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td class="fw-semibold"><a href="<?= BASE_URL ?>/products/view.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($p['name']) ?></a></td>
                        <td class="text-muted small"><?= htmlspecialchars($p['sku']) ?></td>
                        <td><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                        <td><?= (int) $p['quantity_on_hand'] ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($p['expiration_date']))) ?></td>
                        <td><span class="badge-status <?= $badgeMap[$p['expiration_status']] ?>"><?= str_replace('_', ' ', ucfirst($p['expiration_status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

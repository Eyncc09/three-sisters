<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('products.view');

$flash = null;

// Handle archive/activate toggle (management permission only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    requirePermission('products.manage');
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $id = (int) $_POST['id'];
        $newStatus = $_POST['new_status'] === 'archived' ? 'archived' : 'active';
        ProductService::setStatus($id, $newStatus, (int) $_SESSION['user_id']);
        header('Location: ' . BASE_URL . '/products/index.php?' . $_SERVER['QUERY_STRING'] . '&msg=status');
        exit;
    }
}

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'category_id' => $_GET['category_id'] ?? '',
    'brand_id' => $_GET['brand_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'stock_status' => $_GET['stock_status'] ?? '',
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = ProductService::search($filters, $page);
$categories = CategoryService::all(true);
$brands = BrandService::all(true);

if (isset($_GET['msg']) && $_GET['msg'] === 'status') {
    $flash = ['type' => 'success', 'text' => 'Product status updated.'];
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $flash = ['type' => 'success', 'text' => 'Product created successfully.'];
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $flash = ['type' => 'success', 'text' => 'Product updated successfully.'];
}

$badgeMap = [
    'safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger',
    'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger',
];

$pageTitle = 'Products';
$activeNav = 'products';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Products</h1>
        <p class="text-muted mb-0 small">Manage your beauty product catalog.</p>
    </div>
    <?php if (can('products.manage')): ?>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/products/categories.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags me-1"></i>Categories</a>
            <a href="<?= BASE_URL ?>/products/brands.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-award me-1"></i>Brands</a>
            <a href="<?= BASE_URL ?>/products/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2" role="alert">
        <?= htmlspecialchars($flash['text']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Name or SKU" value="<?= htmlspecialchars($filters['q']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filters['category_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Brand</label>
            <select name="brand_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $filters['brand_id'] == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Stock Status</label>
            <select name="stock_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="safe" <?= $filters['stock_status'] === 'safe' ? 'selected' : '' ?>>Safe</option>
                <option value="low" <?= $filters['stock_status'] === 'low' ? 'selected' : '' ?>>Low</option>
                <option value="critical" <?= $filters['stock_status'] === 'critical' ? 'selected' : '' ?>>Critical</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Active &amp; Archived</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active only</option>
                <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived only</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$result['items']): ?>
        <div class="empty-state">
            <i class="bi bi-box-seam"></i>
            <p class="mb-0">No products match your filters.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                <tr>
                    <th>Product</th><th>SKU</th><th>Category</th><th>Price</th>
                    <th>Stock</th><th>Expiration</th><th>Status</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $p): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/products/view.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($p['name']) ?></a>
                            <div class="text-muted small"><?= htmlspecialchars($p['brand_name'] ?? '—') ?></div>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($p['sku']) ?></td>
                        <td><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                        <td>₱<?= number_format((float) $p['selling_price'], 2) ?></td>
                        <td>
                            <?= (int) $p['quantity_on_hand'] ?>
                            <span class="badge-status <?= $badgeMap[$p['stock_status']] ?>"><?= ucfirst($p['stock_status']) ?></span>
                        </td>
                        <td>
                            <?php if ($p['expiration_status']): ?>
                                <span class="badge-status <?= $badgeMap[$p['expiration_status']] ?? 'badge-neutral' ?>"><?= str_replace('_', ' ', ucfirst($p['expiration_status'])) ?></span>
                            <?php else: ?>
                                <span class="text-muted small">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-status <?= $p['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($p['status']) ?></span>
                        </td>
                        <td class="text-end">
                            <?php if (can('products.manage')): ?>
                                <a href="<?= BASE_URL ?>/products/edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('<?= $p['status'] === 'active' ? 'Archive' : 'Reactivate' ?> this product?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $p['status'] === 'active' ? 'archived' : 'active' ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-<?= $p['status'] === 'active' ? 'danger' : 'success' ?>">
                                        <i class="bi bi-<?= $p['status'] === 'active' ? 'archive' : 'arrow-counterclockwise' ?>"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="<?= BASE_URL ?>/products/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($result['pages'] > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
<?php require __DIR__ . '/../components/footer.php'; ?>

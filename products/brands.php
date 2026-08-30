<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('products.manage');

$errors = [];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if ($action === 'create' || $action === 'update') {
            $editId = $action === 'update' ? (int) $_POST['id'] : null;
            if ($name === '') {
                $errors['name'] = 'Brand name is required.';
            } elseif (BrandService::nameExists($name, $editId)) {
                $errors['name'] = 'A brand with this name already exists.';
            } else {
                $data = ['name' => $name, 'description' => $description, 'status' => $status];
                if ($action === 'create') {
                    BrandService::create($data, (int) $_SESSION['user_id']);
                    $flash = ['type' => 'success', 'text' => 'Brand added.'];
                } else {
                    BrandService::update($editId, $data, (int) $_SESSION['user_id']);
                    $flash = ['type' => 'success', 'text' => 'Brand updated.'];
                }
            }
        }
    }
}

$brands = BrandService::all();
$pageTitle = 'Brands';
$activeNav = 'products';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <nav class="breadcrumb"><a href="<?= BASE_URL ?>/products/index.php" class="text-decoration-none">Products</a>&nbsp;/&nbsp;Brands</nav>
        <h1 class="page-title h4 mb-0">Brands</h1>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#brandModal" onclick="openBrandModal()"><i class="bi bi-plus-lg me-1"></i>Add Brand</button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($errors['name'])): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($errors['name']) ?></div>
<?php endif; ?>

<div class="ts-card">
    <?php if (!$brands): ?>
        <div class="empty-state"><i class="bi bi-award"></i><p class="mb-0">No brands yet. Add your first one.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Name</th><th>Description</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($brands as $b): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($b['name']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($b['description'] ?? '—') ?></td>
                        <td><span class="badge-status <?= $b['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($b['status']) ?></span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary"
                                    onclick='openBrandModal(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    data-bs-toggle="modal" data-bs-target="#brandModal">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="brandModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="brandForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="brand_action" value="create">
                <input type="hidden" name="id" id="brand_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="brandModalTitle">Add Brand</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="brand_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="brand_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Status</label>
                        <select name="status" id="brand_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openBrandModal(brand) {
    document.getElementById('brandModalTitle').textContent = brand ? 'Edit Brand' : 'Add Brand';
    document.getElementById('brand_action').value = brand ? 'update' : 'create';
    document.getElementById('brand_id').value = brand ? brand.id : '';
    document.getElementById('brand_name').value = brand ? brand.name : '';
    document.getElementById('brand_description').value = brand ? (brand.description || '') : '';
    document.getElementById('brand_status').value = brand ? brand.status : 'active';
}
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

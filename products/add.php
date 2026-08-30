<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('products.manage');

$errors = [];
$formData = ['status' => 'active', 'reorder_level' => '10', 'initial_stock' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $errors['_general'] = 'Your session expired. Please resubmit the form.';
    } else {
        ['data' => $formData, 'errors' => $errors] = ProductService::validate($_POST);
        if (!$errors) {
            $id = ProductService::create($formData, (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/products/index.php?msg=created');
            exit;
        }
    }
}

$categories = CategoryService::all(true);
$brands = BrandService::all(true);
$distributors = db()->query("SELECT * FROM distributors WHERE status = 'active' ORDER BY name")->fetchAll();
$isEdit = false;

$pageTitle = 'Add Product';
$activeNav = 'products';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/products/index.php" class="text-decoration-none">Products</a>&nbsp;/&nbsp;Add</nav>
    <h1 class="page-title h4 mb-0">Add Product</h1>
</div>

<?php if (isset($errors['_general'])): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($errors['_general']) ?></div>
<?php endif; ?>

<div class="ts-card p-4">
    <form method="POST" novalidate>
        <?= csrfField() ?>
        <?php require __DIR__ . '/../components/product-form.php'; ?>
        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Product</button>
            <a href="<?= BASE_URL ?>/products/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

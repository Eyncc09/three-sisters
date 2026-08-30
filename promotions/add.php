<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('promotions.manage');

$errors = [];
$formData = [];
// Pre-fill from a "Review Bundle" link (Stage 4D basket analysis) — GET only,
// just pre-checks checkboxes in the form the Owner still reviews and submits;
// nothing is created until the Owner actually clicks Save Promotion.
$selectedProductIds = $_SERVER['REQUEST_METHOD'] !== 'POST'
    ? array_values(array_filter(array_map('intval', $_GET['product_ids'] ?? []), fn($id) => $id > 0))
    : [];
$activateNow = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $errors['_general'] = 'Your session expired. Please resubmit the form.';
    } else {
        $selectedProductIds = array_map('intval', $_POST['product_ids'] ?? []);
        $activateNow = !empty($_POST['activate_now']);
        ['data' => $formData, 'errors' => $errors] = PromotionService::validate($_POST, $selectedProductIds, $activateNow);

        if (!$errors) {
            $id = PromotionService::create($formData, $selectedProductIds, $activateNow, (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/promotions/index.php?msg=created');
            exit;
        }
    }
}

$allProducts = db()->query(
    "SELECT p.id, p.sku, p.name, p.selling_price, p.reorder_level, p.expiration_date, c.name AS category_name,
            COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN inventory i ON i.product_id = p.id
     WHERE p.status = 'active'
     ORDER BY p.name ASC"
)->fetchAll();
foreach ($allProducts as &$p) {
    $p['stock_status'] = InventoryService::stockStatus((int) $p['quantity_on_hand'], (int) $p['reorder_level']);
    $p['expiration_status'] = InventoryService::expirationStatus($p['expiration_date']);
}
unset($p);

$isEdit = false;
$pageTitle = 'Add Promotion';
$activeNav = 'promotions';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/promotions/index.php" class="text-decoration-none">Promotions</a>&nbsp;/&nbsp;Add</nav>
    <h1 class="page-title h4 mb-0">Add Promotion</h1>
</div>

<?php if (isset($errors['_general'])): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($errors['_general']) ?></div>
<?php endif; ?>

<div class="ts-card p-4">
    <form method="POST" novalidate>
        <?= csrfField() ?>
        <?php require __DIR__ . '/../components/promotion-form.php'; ?>
        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Promotion</button>
            <a href="<?= BASE_URL ?>/promotions/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

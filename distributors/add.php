<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('distributors.manage');

$errors = [];
$formData = ['status' => 'active', 'lead_time_days' => '7'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $errors['_general'] = 'Your session expired. Please resubmit the form.';
    } else {
        ['data' => $formData, 'errors' => $errors] = DistributorService::validate($_POST);
        if (!$errors) {
            $id = DistributorService::create($formData, (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/distributors/view.php?id=' . $id . '&msg=created');
            exit;
        }
    }
}

$pageTitle = 'Add Distributor';
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/distributors/index.php" class="text-decoration-none">Distributors</a>&nbsp;/&nbsp;Add</nav>
    <h1 class="page-title h4 mb-0">Add Distributor</h1>
</div>
<?php if (isset($errors['_general'])): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($errors['_general']) ?></div><?php endif; ?>
<div class="ts-card p-4">
    <form method="POST" novalidate>
        <?= csrfField() ?>
        <?php require __DIR__ . '/../components/distributor-form.php'; ?>
        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Distributor</button>
            <a href="<?= BASE_URL ?>/distributors/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

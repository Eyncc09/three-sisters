<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('promotions.manage');

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        try {
            PromotionService::setActive((int) $_POST['id'], $_POST['new_state'] === 'active', (int) $_SESSION['user_id']);
            header('Location: ' . BASE_URL . '/promotions/index.php?msg=status');
            exit;
        } catch (RuntimeException $e) {
            $flash = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'status') {
    $flash = ['type' => 'success', 'text' => 'Promotion status updated.'];
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $flash = ['type' => 'success', 'text' => 'Promotion created.'];
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $flash = ['type' => 'success', 'text' => 'Promotion updated.'];
}

$filters = ['q' => trim($_GET['q'] ?? ''), 'status' => $_GET['status'] ?? ''];
$promotions = PromotionService::all($filters);

$statusBadge = ['scheduled' => 'badge-info', 'active' => 'badge-success', 'inactive' => 'badge-neutral', 'ended' => 'badge-neutral'];

$pageTitle = 'Promotions';
$activeNav = 'promotions';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Promotions</h1>
        <p class="text-muted mb-0 small">Manage discounts and view promo performance.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/analytics/basket.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-basket me-1"></i>Basket Analysis</a>
        <a href="<?= BASE_URL ?>/promotions/performance.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-graph-up me-1"></i>Promo Performance</a>
        <a href="<?= BASE_URL ?>/promotions/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Promotion</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Promotion name" value="<?= htmlspecialchars($filters['q']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="scheduled" <?= $filters['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="ended" <?= $filters['status'] === 'ended' ? 'selected' : '' ?>>Ended</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$promotions): ?>
        <div class="empty-state"><i class="bi bi-tags"></i><p class="mb-0">No promotions yet. Create your first one.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Promotion</th><th>Discount</th><th>Products</th><th>Start</th><th>End</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($promotions as $p): ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>/promotions/view.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($p['name']) ?></a></td>
                        <td><?= $p['discount_type'] === 'percentage' ? number_format((float) $p['discount_value'], 0) . '% off' : '₱' . number_format((float) $p['discount_value'], 2) . ' off/unit' ?></td>
                        <td><?= (int) $p['product_count'] ?></td>
                        <td class="small"><?= htmlspecialchars(date('M j, Y', strtotime($p['start_date']))) ?></td>
                        <td class="small"><?= htmlspecialchars(date('M j, Y', strtotime($p['end_date']))) ?></td>
                        <td><span class="badge-status <?= $statusBadge[$p['effective_status']] ?>"><?= ucfirst($p['effective_status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/promotions/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            <a href="<?= BASE_URL ?>/promotions/edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= $p['effective_status'] === 'active' ? 'Deactivate' : 'Activate' ?> this promotion?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="new_state" value="<?= $p['effective_status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?= $p['effective_status'] === 'active' ? 'danger' : 'success' ?>">
                                    <i class="bi bi-<?= $p['effective_status'] === 'active' ? 'pause' : 'play' ?>-fill"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

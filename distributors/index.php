<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();

// A distributor account only ever sees its own profile — never a roster of other distributors.
if (hasRole(ROLE_DISTRIBUTOR)) {
    if (!$_SESSION['distributor_id']) {
        renderForbidden();
    }
    header('Location: ' . BASE_URL . '/distributors/view.php?id=' . $_SESSION['distributor_id']);
    exit;
}

requirePermission('distributors.manage');

$filters = ['q' => trim($_GET['q'] ?? ''), 'status' => $_GET['status'] ?? ''];
$distributors = DistributorService::all($filters);

$pageTitle = 'Distributors';
$activeNav = 'distributors';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Distributors</h1>
        <p class="text-muted mb-0 small">Suppliers, lead times, and purchase orders.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/distributors/chat.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chat-dots me-1"></i>Messages</a>
        <a href="<?= BASE_URL ?>/distributors/purchase-orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-truck me-1"></i>Purchase Orders</a>
        <a href="<?= BASE_URL ?>/distributors/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Distributor</a>
    </div>
</div>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Distributor name" value="<?= htmlspecialchars($filters['q']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$distributors): ?>
        <div class="empty-state"><i class="bi bi-truck"></i><p class="mb-0">No distributors yet. Add your first one.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Distributor</th><th>Contact</th><th>Lead Time</th><th>Products</th><th>Open POs</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($distributors as $d): ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>/distributors/view.php?id=<?= $d['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($d['name']) ?></a></td>
                        <td class="small"><?= htmlspecialchars($d['contact_person'] ?: '—') ?><?= $d['phone'] ? '<br><span class="text-muted">' . htmlspecialchars($d['phone']) . '</span>' : '' ?></td>
                        <td><?= (int) $d['lead_time_days'] ?> days</td>
                        <td><?= (int) $d['product_count'] ?></td>
                        <td><?= (int) $d['open_po_count'] ?></td>
                        <td><span class="badge-status <?= $d['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($d['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/distributors/view.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            <a href="<?= BASE_URL ?>/distributors/edit.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('resellers.manage');

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $data = [
            'full_name' => $fullName,
            'business_name' => trim($_POST['business_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'registration_date' => $_POST['registration_date'] ?? date('Y-m-d'),
            'status' => in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active',
        ];
        if ($fullName === '') {
            $flash = ['type' => 'danger', 'text' => 'Reseller name is required.'];
        } elseif ($action === 'create') {
            ResellerService::create($data, (int) $_SESSION['user_id']);
            $flash = ['type' => 'success', 'text' => 'Reseller added.'];
        } elseif ($action === 'update') {
            ResellerService::update((int) $_POST['id'], $data, (int) $_SESSION['user_id']);
            $flash = ['type' => 'success', 'text' => 'Reseller updated.'];
        }
    }
}

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = ResellerService::search($q, $status ?: null, $page);

$pageTitle = 'Resellers';
$activeNav = 'resellers';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Resellers</h1>
        <p class="text-muted mb-0 small">Manage your reseller partners.</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#resellerModal" onclick="openResellerModal()"><i class="bi bi-plus-lg me-1"></i>Add Reseller</button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, business, or phone" value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$result['items']): ?>
        <div class="empty-state"><i class="bi bi-shop"></i><p class="mb-0">No resellers found.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Name</th><th>Business</th><th>Phone</th><th>Registered</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($result['items'] as $r): ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>/resellers/view.php?id=<?= $r['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($r['full_name']) ?></a></td>
                        <td><?= htmlspecialchars($r['business_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['phone'] ?? '—') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars(date('M j, Y', strtotime($r['registration_date']))) ?></td>
                        <td><span class="badge-status <?= $r['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/resellers/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#resellerModal"
                                    onclick='openResellerModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
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

<?php if ($result['pages'] > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
            <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<div class="modal fade" id="resellerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="rs_action" value="create">
                <input type="hidden" name="id" id="rs_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="resellerModalTitle">Add Reseller</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="rs_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="business_name" id="rs_business" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="rs_phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="rs_email" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" id="rs_address" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Registration Date</label>
                            <input type="date" name="registration_date" id="rs_reg_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="rs_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Reseller</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openResellerModal(r) {
    document.getElementById('resellerModalTitle').textContent = r ? 'Edit Reseller' : 'Add Reseller';
    document.getElementById('rs_action').value = r ? 'update' : 'create';
    document.getElementById('rs_id').value = r ? r.id : '';
    document.getElementById('rs_name').value = r ? r.full_name : '';
    document.getElementById('rs_business').value = r ? (r.business_name || '') : '';
    document.getElementById('rs_phone').value = r ? (r.phone || '') : '';
    document.getElementById('rs_email').value = r ? (r.email || '') : '';
    document.getElementById('rs_address').value = r ? (r.address || '') : '';
    document.getElementById('rs_reg_date').value = r ? r.registration_date : new Date().toISOString().slice(0,10);
    document.getElementById('rs_status').value = r ? r.status : 'active';
}
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

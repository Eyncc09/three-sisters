<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('customers.manage') && !can('customers.view')) { renderForbidden(); }

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('customers.manage');
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $data = [
            'full_name' => $fullName,
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
        ];
        if ($fullName === '') {
            $flash = ['type' => 'danger', 'text' => 'Customer name is required.'];
        } elseif ($action === 'create') {
            CustomerService::create($data, (int) $_SESSION['user_id']);
            $flash = ['type' => 'success', 'text' => 'Customer added.'];
        } elseif ($action === 'update') {
            CustomerService::update((int) $_POST['id'], $data, (int) $_SESSION['user_id']);
            $flash = ['type' => 'success', 'text' => 'Customer updated.'];
        }
    }
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = CustomerService::search($q, $page);

$pageTitle = 'Customers';
$activeNav = 'customers';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Customers</h1>
        <p class="text-muted mb-0 small">Retail customer records.</p>
    </div>
    <?php if (can('customers.manage')): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openCustomerModal()"><i class="bi bi-person-plus me-1"></i>Add Customer</button>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, phone, or email" value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="ts-card">
    <?php if (!$result['items']): ?>
        <div class="empty-state"><i class="bi bi-people"></i><p class="mb-0">No customers found.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($result['items'] as $c): ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>/customers/view.php?id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($c['full_name']) ?></a></td>
                        <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            <?php if (can('customers.manage')): ?>
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#customerModal"
                                        onclick='openCustomerModal(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
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
    <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
            <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php if (can('customers.manage')): ?>
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="cust_action" value="create">
                <input type="hidden" name="id" id="cust_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalTitle">Add Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="cust_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="cust_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="cust_email" class="form-control">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" id="cust_address" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openCustomerModal(c) {
    document.getElementById('customerModalTitle').textContent = c ? 'Edit Customer' : 'Add Customer';
    document.getElementById('cust_action').value = c ? 'update' : 'create';
    document.getElementById('cust_id').value = c ? c.id : '';
    document.getElementById('cust_name').value = c ? c.full_name : '';
    document.getElementById('cust_phone').value = c ? (c.phone || '') : '';
    document.getElementById('cust_email').value = c ? (c.email || '') : '';
    document.getElementById('cust_address').value = c ? (c.address || '') : '';
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../components/footer.php'; ?>

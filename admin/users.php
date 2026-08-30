<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('users.manage');

function roleNameHelper(array $roles, int $roleId): ?string
{
    foreach ($roles as $r) { if ((int) $r['id'] === $roleId) return $r['name']; }
    return null;
}

$flash = null;
$errors = [];
$editUser = null;

$roles = db()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$distributors = db()->query("SELECT id, name FROM distributors WHERE status = 'active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_status') {
            $targetId = (int) $_POST['id'];
            if ($targetId === (int) $_SESSION['user_id']) {
                $flash = ['type' => 'danger', 'text' => 'You cannot deactivate your own account.'];
            } else {
                $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
                db()->prepare('UPDATE users SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $targetId]);
                AuditLogger::log((int) $_SESSION['user_id'], 'user.' . $newStatus, 'admin', 'users', $targetId, ['status' => $newStatus]);
                header('Location: ' . BASE_URL . '/admin/users.php?msg=status');
                exit;
            }
        } elseif ($action === 'create' || $action === 'update') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $distributorId = ($roleId && roleNameHelper($roles, $roleId) === 'distributor') ? ((int) ($_POST['distributor_id'] ?? 0) ?: null) : null;
            $password = (string) ($_POST['password'] ?? '');
            $editId = $action === 'update' ? (int) $_POST['id'] : null;

            if ($username === '' || !preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
                $errors['username'] = 'Username must be 3-50 characters (letters, numbers, underscore, period only).';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if ($fullName === '') {
                $errors['full_name'] = 'Full name is required.';
            }
            if (!$roleId || !array_filter($roles, fn($r) => (int) $r['id'] === $roleId)) {
                $errors['role_id'] = 'Select a valid role.';
            }
            if ($action === 'create' && strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif ($action === 'update' && $password !== '' && strlen($password) < 8) {
                $errors['password'] = 'New password must be at least 8 characters (leave blank to keep current password).';
            }

            if (!$errors) {
                $dupStmt = db()->prepare('SELECT COUNT(*) FROM users WHERE (username = :u OR email = :e)' . ($editId ? ' AND id != :id' : ''));
                $dupParams = ['u' => $username, 'e' => $email];
                if ($editId) $dupParams['id'] = $editId;
                $dupStmt->execute($dupParams);
                if ((int) $dupStmt->fetchColumn() > 0) {
                    $errors['username'] = 'That username or email is already in use.';
                }
            }

            if (!$errors) {
                if ($action === 'create') {
                    $stmt = db()->prepare(
                        'INSERT INTO users (role_id, username, email, password_hash, full_name, phone, distributor_id, status)
                         VALUES (:role_id, :username, :email, :hash, :full_name, :phone, :distributor_id, "active")'
                    );
                    $stmt->execute([
                        'role_id' => $roleId, 'username' => $username, 'email' => $email,
                        'hash' => password_hash($password, PASSWORD_BCRYPT), 'full_name' => $fullName,
                        'phone' => $phone ?: null, 'distributor_id' => $distributorId,
                    ]);
                    $newId = (int) db()->lastInsertId();
                    AuditLogger::log((int) $_SESSION['user_id'], 'user.created', 'admin', 'users', $newId, ['username' => $username, 'role_id' => $roleId]);
                    header('Location: ' . BASE_URL . '/admin/users.php?msg=created');
                    exit;
                } else {
                    $sql = 'UPDATE users SET role_id = :role_id, username = :username, email = :email,
                                full_name = :full_name, phone = :phone, distributor_id = :distributor_id';
                    $params = [
                        'role_id' => $roleId, 'username' => $username, 'email' => $email,
                        'full_name' => $fullName, 'phone' => $phone ?: null, 'distributor_id' => $distributorId, 'id' => $editId,
                    ];
                    if ($password !== '') {
                        $sql .= ', password_hash = :hash';
                        $params['hash'] = password_hash($password, PASSWORD_BCRYPT);
                    }
                    $sql .= ' WHERE id = :id';
                    db()->prepare($sql)->execute($params);
                    AuditLogger::log((int) $_SESSION['user_id'], 'user.updated', 'admin', 'users', $editId, ['username' => $username]);
                    header('Location: ' . BASE_URL . '/admin/users.php?msg=updated');
                    exit;
                }
            } else {
                $editUser = array_merge(['id' => $editId], $_POST);
            }
        }
    }
}

if (isset($_GET['msg'])) {
    $messages = ['created' => 'User account created.', 'updated' => 'User account updated.', 'status' => 'Account status updated.'];
    if (isset($messages[$_GET['msg']])) $flash = ['type' => 'success', 'text' => $messages[$_GET['msg']]];
}

$users = db()->query(
    "SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.full_name ASC"
)->fetchAll();

$distributorRole = array_values(array_filter($roles, fn($r) => $r['name'] === 'distributor'));
$distributorRoleId = $distributorRole ? (int) $distributorRole[0]['id'] : 0;

$pageTitle = 'User Management';
$activeNav = 'users';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">User Management</h1>
        <p class="text-muted mb-0 small">Create and manage staff, owner, and distributor accounts.</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal()"><i class="bi bi-person-plus me-1"></i>Add User</button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger py-2"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="ts-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($u['username']) ?></td>
                    <td class="small"><?= htmlspecialchars($u['email']) ?></td>
                    <td><span class="badge-status badge-info"><?= htmlspecialchars(ucfirst($u['role_name'])) ?></span></td>
                    <td><span class="badge-status <?= $u['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= ucfirst($u['status']) ?></span></td>
                    <td class="small text-muted"><?= $u['last_login_at'] ? htmlspecialchars(date('M j, Y g:ia', strtotime($u['last_login_at']))) : 'Never' ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" onclick='openUserModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-pencil"></i></button>
                        <?php if ((int) $u['id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this account?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $u['status'] === 'active' ? 'slash-circle' : 'check-circle' ?>"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="u_action" value="create">
                <input type="hidden" name="id" id="u_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="full_name" id="u_full_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Username <span class="text-danger">*</span></label><input type="text" name="username" id="u_username" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" id="u_email" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" id="u_phone" class="form-control"></div>
                        <div class="col-md-6">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role_id" id="u_role_id" class="form-select" required onchange="toggleDistributorField()">
                                <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= ucfirst($r['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="u_distributor_wrap">
                            <label class="form-label">Distributor</label>
                            <select name="distributor_id" id="u_distributor_id" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($distributors as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password <span id="u_pw_required" class="text-danger">*</span></label>
                            <input type="password" name="password" id="u_password" class="form-control" placeholder="Min. 8 characters" minlength="8">
                            <div class="form-text" id="u_pw_hint">Leave blank when editing to keep the current password.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const distributorRoleId = <?= (int) $distributorRoleId ?>;
function toggleDistributorField() {
    const isDistributor = document.getElementById('u_role_id').value == distributorRoleId;
    document.getElementById('u_distributor_wrap').classList.toggle('d-none', !isDistributor);
}
function openUserModal(u) {
    document.getElementById('userModalTitle').textContent = u ? 'Edit User' : 'Add User';
    document.getElementById('u_action').value = u ? 'update' : 'create';
    document.getElementById('u_id').value = u ? u.id : '';
    document.getElementById('u_full_name').value = u ? u.full_name : '';
    document.getElementById('u_username').value = u ? u.username : '';
    document.getElementById('u_email').value = u ? u.email : '';
    document.getElementById('u_phone').value = u ? (u.phone || '') : '';
    document.getElementById('u_role_id').value = u ? u.role_id : <?= (int) ($roles[0]['id'] ?? 1) ?>;
    document.getElementById('u_distributor_id').value = u ? (u.distributor_id || '') : '';
    document.getElementById('u_password').value = '';
    document.getElementById('u_password').required = !u;
    document.getElementById('u_pw_required').style.display = u ? 'none' : 'inline';
    document.getElementById('u_pw_hint').style.display = u ? 'block' : 'none';
    toggleDistributorField();
}
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

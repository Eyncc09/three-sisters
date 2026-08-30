<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('system.settings');

$flash = null;

// Keys we expose an editor for — matches what seed.sql actually populates.
$editableKeys = [
    'business_name' => ['label' => 'Business Name', 'type' => 'text'],
    'business_address' => ['label' => 'Business Address', 'type' => 'text'],
    'low_stock_default_threshold_days' => ['label' => 'Low Stock Default Threshold (days)', 'type' => 'number'],
    'expiration_alert_window_days' => ['label' => 'Expiration Alert Window (days)', 'type' => 'number'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $stmt = db()->prepare('UPDATE system_settings SET setting_value = :val, updated_by = :uid WHERE setting_key = :key');
        $changed = [];
        foreach ($editableKeys as $key => $meta) {
            if (isset($_POST[$key])) {
                $val = trim((string) $_POST[$key]);
                $stmt->execute(['val' => $val, 'uid' => (int) $_SESSION['user_id'], 'key' => $key]);
                $changed[$key] = $val;
            }
        }
        AuditLogger::log((int) $_SESSION['user_id'], 'settings.updated', 'admin', 'system_settings', null, $changed);
        header('Location: ' . BASE_URL . '/settings/index.php?msg=saved');
        exit;
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'saved') {
    $flash = ['type' => 'success', 'text' => 'Settings saved.'];
}

$rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Settings</h1>
    <p class="text-muted mb-0 small">Business information and operational thresholds used throughout the system.</p>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card p-4" style="max-width: 640px;">
    <form method="POST">
        <?= csrfField() ?>
        <?php foreach ($editableKeys as $key => $meta): ?>
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars($meta['label']) ?></label>
                <input type="<?= $meta['type'] ?>" name="<?= $key ?>" class="form-control" value="<?= htmlspecialchars($rows[$key] ?? '') ?>">
                <?php if ($key === 'low_stock_default_threshold_days'): ?>
                    <div class="form-text">Reference value only — actual low/critical stock badges use each product's own reorder level (Inventory module).</div>
                <?php elseif ($key === 'expiration_alert_window_days'): ?>
                    <div class="form-text">Reference value only — the live "Expiring Soon" calculation across the system currently uses a fixed 30-day window.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Settings</button>
    </form>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

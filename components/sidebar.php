<?php
/**
 * Sidebar navigation.
 * Expects $activeNav (string key) to be set by the including page.
 * Visibility per item is controlled by `roles` (which roles can see it at
 * all) — this mirrors the Phase 0 permission matrix. Some linked pages are
 * built in later stages; until then they 404, which is expected during
 * incremental rollout.
 */

$navItems = [
    ['key' => 'dashboard',     'label' => 'Dashboard',       'icon' => 'bi-speedometer2',    'url' => '/index.php',                'roles' => [ROLE_ADMIN, ROLE_OWNER, ROLE_STAFF, ROLE_DISTRIBUTOR]],
    ['key' => 'pos',           'label' => 'POS',              'icon' => 'bi-cash-coin',       'url' => '/pos/index.php',            'roles' => [ROLE_OWNER, ROLE_STAFF]],
    ['key' => 'orders',        'label' => 'Transaction History', 'icon' => 'bi-receipt',      'url' => '/orders/index.php',         'roles' => [ROLE_OWNER, ROLE_STAFF]],
    ['key' => 'products',      'label' => 'Products',         'icon' => 'bi-box-seam',        'url' => '/products/index.php',       'roles' => [ROLE_OWNER, ROLE_STAFF]],
    ['key' => 'inventory',     'label' => 'Inventory',        'icon' => 'bi-clipboard-data',  'url' => '/inventory/index.php',      'roles' => [ROLE_OWNER]],
    ['key' => 'customers',     'label' => 'Customers',        'icon' => 'bi-people',          'url' => '/customers/index.php',      'roles' => [ROLE_OWNER, ROLE_STAFF]],
    ['key' => 'resellers',     'label' => 'Resellers',        'icon' => 'bi-shop',            'url' => '/resellers/index.php',      'roles' => [ROLE_OWNER]],
    ['key' => 'promotions',    'label' => 'Promotions',       'icon' => 'bi-tags',            'url' => '/promotions/index.php',     'roles' => [ROLE_OWNER]],
    ['key' => 'analytics',     'label' => 'Analytics',        'icon' => 'bi-graph-up-arrow',  'url' => '/analytics/index.php',      'roles' => [ROLE_OWNER]],
    ['key' => 'distributors',  'label' => 'Distributors',     'icon' => 'bi-truck',           'url' => '/distributors/index.php',   'roles' => [ROLE_OWNER, ROLE_DISTRIBUTOR]],
    ['key' => 'tiktok',        'label' => 'TikTok Shop',      'icon' => 'bi-tiktok',          'url' => '/tiktok/index.php',         'roles' => [ROLE_OWNER]],
    ['key' => 'reports',       'label' => 'Reports',          'icon' => 'bi-file-earmark-bar-graph', 'url' => '/reports/index.php', 'roles' => [ROLE_OWNER, ROLE_STAFF]],
    ['key' => 'notifications', 'label' => 'Notifications',    'icon' => 'bi-bell',            'url' => '/notifications/index.php',  'roles' => [ROLE_ADMIN, ROLE_OWNER, ROLE_STAFF, ROLE_DISTRIBUTOR]],
    ['key' => 'audit',         'label' => 'Audit Trail',      'icon' => 'bi-journal-text',    'url' => '/audit/index.php',          'roles' => [ROLE_ADMIN, ROLE_OWNER]],
    ['key' => 'users',         'label' => 'User Management',  'icon' => 'bi-person-gear',     'url' => '/admin/users.php',          'roles' => [ROLE_ADMIN]],
    ['key' => 'backup',        'label' => 'Backup & Restore', 'icon' => 'bi-hdd-network',      'url' => '/admin/backup.php',         'roles' => [ROLE_ADMIN]],
    ['key' => 'settings',      'label' => 'Settings',         'icon' => 'bi-gear',            'url' => '/settings/index.php',       'roles' => [ROLE_ADMIN, ROLE_OWNER]],
];
?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="logo-mark"><i class="bi bi-flower2"></i></div>
        <span><?= htmlspecialchars(APP_NAME) ?></span>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($navItems as $item): ?>
            <?php if (!hasRole(...$item['roles'])) continue; ?>
            <a class="nav-link <?= ($activeNav ?? '') === $item['key'] ? 'active' : '' ?>"
               href="<?= BASE_URL . $item['url'] ?>">
                <i class="bi <?= $item['icon'] ?>"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        Logged in as <?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?>
    </div>
</aside>

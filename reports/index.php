<?php
require_once __DIR__ . '/../core/bootstrap.php';
requireLogin();
if (!can('reports.generate') && !can('reports.own_sales')) { renderForbidden(); }

$isFullReports = can('reports.generate');

$dateFrom = ($_GET['date_from'] ?? '') ?: date('Y-m-d', strtotime('-29 days'));
$dateTo = ($_GET['date_to'] ?? '') ?: date('Y-m-d');
$d1 = DateTime::createFromFormat('Y-m-d', $dateFrom);
$d2 = DateTime::createFromFormat('Y-m-d', $dateTo);
if (!$d1 || $d1->format('Y-m-d') !== $dateFrom) $dateFrom = date('Y-m-d', strtotime('-29 days'));
if (!$d2 || $d2->format('Y-m-d') !== $dateTo) $dateTo = date('Y-m-d');
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$allowedReports = $isFullReports
    ? ['sales' => 'Sales Report', 'inventory' => 'Inventory Report', 'top_products' => 'Product Report (Top SKU)', 'expiration' => 'Expiration Report']
    : ['my_sales' => 'My Sales Report'];

$report = array_key_exists($_GET['report'] ?? '', $allowedReports) ? $_GET['report'] : array_key_first($allowedReports);
$filters = ['date_from' => $dateFrom, 'date_to' => $dateTo];

// ---------- CSV export (real export — no library dependency; PDF/Excel-formatted exports remain future scope) ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = [];
    $headers = [];
    switch ($report) {
        case 'sales':
            $headers = ['Date', 'Revenue', 'Transactions'];
            foreach (AnalyticsService::salesOverTime($filters, 'daily') as $r) {
                $rows[] = [$r['period'], $r['revenue'], $r['transactions']];
            }
            break;
        case 'inventory':
            $headers = ['SKU', 'Product', 'Current Stock', 'Reorder Level', 'Avg Daily Sales', 'Est. Days Remaining', 'Recommendation'];
            foreach (AnalyticsService::stockAnalysis($filters) as $r) {
                $rows[] = [$r['sku'], $r['name'], $r['current_stock'], $r['reorder_level'], $r['avg_daily_sales'], $r['estimated_days_remaining'] ?? 'N/A', $r['recommendation']];
            }
            break;
        case 'top_products':
            $headers = ['Rank', 'SKU', 'Product', 'Category', 'Units Sold', 'Revenue', 'Revenue %'];
            foreach (AnalyticsService::topSku($filters, 'revenue', 50) as $r) {
                $rows[] = [$r['rank'], $r['sku'], $r['name'], $r['category_name'], $r['units_sold'], $r['revenue'], $r['revenue_percentage']];
            }
            break;
        case 'expiration':
            $headers = ['SKU', 'Product', 'Expiration Date', 'Current Stock', 'Status'];
            foreach (AnalyticsService::expirationAnalysis()['items'] as $r) {
                $rows[] = [$r['sku'], $r['name'], $r['expiration_date'], $r['current_stock'], $r['status']];
            }
            break;
        case 'my_sales':
            $headers = ['Sale #', 'Date', 'Customer Type', 'Total', 'Payment Method', 'Payment Status'];
            $result = OrderService::transactionHistory($filters, 1, (int) $_SESSION['user_id']);
            foreach ($result['items'] as $r) {
                $rows[] = [$r['sale_number'], $r['created_at'], $r['customer_type'], $r['total_amount'], $r['payment_method'], $r['payment_status']];
            }
            break;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $report . '_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    AuditLogger::log((int) $_SESSION['user_id'], 'report.exported', 'reports', null, null, ['report' => $report, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    exit;
}

// ---------- On-screen data ----------
$salesSeries = $inventoryRows = $topSkuRows = $expirationRows = $mySalesRows = [];
if ($report === 'sales') $salesSeries = AnalyticsService::salesOverTime($filters, 'daily');
if ($report === 'inventory') $inventoryRows = AnalyticsService::stockAnalysis($filters);
if ($report === 'top_products') $topSkuRows = AnalyticsService::topSku($filters, 'revenue', 50);
if ($report === 'expiration') $expirationRows = AnalyticsService::expirationAnalysis()['items'];
if ($report === 'my_sales') $mySalesRows = OrderService::transactionHistory($filters, max(1, (int) ($_GET['page'] ?? 1)), (int) $_SESSION['user_id'])['items'];

$recBadge = ['Sufficient' => 'badge-success', 'Low Stock' => 'badge-warning', 'Reorder Recommended' => 'badge-warning', 'Urgent Reorder' => 'badge-danger'];
$expBadge = ['safe' => 'badge-success', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];

$pageTitle = 'Reports';
$activeNav = 'reports';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Reports</h1>
    <p class="text-muted mb-0 small">Built on the same analytics engine as the dashboard — nothing here is separately calculated.</p>
</div>

<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small">Report</label>
            <select name="report" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($allowedReports as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $report === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small">From Date</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">To Date</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
        </div>
    </form>
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-download me-1"></i>Export CSV</a>
    <span class="text-muted small ms-2">PDF/Excel-formatted export is planned for a later stage — CSV opens directly in Excel/Sheets.</span>
</div>

<div class="ts-card p-3">
    <?php if ($report === 'sales'): ?>
        <h2 class="h6 mb-3">Sales Report — <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?></h2>
        <?php if (!$salesSeries): ?>
            <div class="empty-state py-4"><i class="bi bi-graph-up"></i><p class="mb-0">No sales data for this period.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Date</th><th>Revenue</th><th>Transactions</th></tr></thead>
                <tbody><?php foreach ($salesSeries as $r): ?>
                    <tr><td><?= htmlspecialchars($r['period']) ?></td><td>₱<?= number_format($r['revenue'], 2) ?></td><td><?= $r['transactions'] ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>

    <?php elseif ($report === 'inventory'): ?>
        <h2 class="h6 mb-3">Inventory Report</h2>
        <?php if (!$inventoryRows): ?>
            <div class="empty-state py-4"><i class="bi bi-clipboard-data"></i><p class="mb-0">No active products found.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>SKU</th><th>Product</th><th>Stock</th><th>Reorder Lvl</th><th>Avg Daily Sales</th><th>Est. Duration</th><th>Recommendation</th></tr></thead>
                <tbody><?php foreach ($inventoryRows as $r): ?>
                    <tr>
                        <td class="small text-muted"><?= htmlspecialchars($r['sku']) ?></td><td><?= htmlspecialchars($r['name']) ?></td>
                        <td><?= $r['current_stock'] ?></td><td><?= $r['reorder_level'] ?></td>
                        <td><?= number_format($r['avg_daily_sales'], 2) ?></td>
                        <td><?= $r['estimated_days_remaining'] === null ? 'N/A' : $r['estimated_days_remaining'] . 'd' ?></td>
                        <td><span class="badge-status <?= $recBadge[$r['recommendation']] ?>"><?= htmlspecialchars($r['recommendation']) ?></span></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>

    <?php elseif ($report === 'top_products'): ?>
        <h2 class="h6 mb-3">Product Report — Top SKU by Revenue</h2>
        <?php if (!$topSkuRows): ?>
            <div class="empty-state py-4"><i class="bi bi-trophy"></i><p class="mb-0">No sales data for this period.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>#</th><th>SKU</th><th>Product</th><th>Category</th><th>Units</th><th>Revenue</th><th>%</th></tr></thead>
                <tbody><?php foreach ($topSkuRows as $r): ?>
                    <tr><td><?= $r['rank'] ?></td><td class="small text-muted"><?= htmlspecialchars($r['sku']) ?></td><td><?= htmlspecialchars($r['name']) ?></td>
                        <td class="small"><?= htmlspecialchars($r['category_name']) ?></td><td><?= $r['units_sold'] ?></td>
                        <td>₱<?= number_format($r['revenue'], 2) ?></td><td><?= $r['revenue_percentage'] ?>%</td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>

    <?php elseif ($report === 'expiration'): ?>
        <h2 class="h6 mb-3">Expiration Report</h2>
        <?php if (!$expirationRows): ?>
            <div class="empty-state py-4"><i class="bi bi-hourglass-split"></i><p class="mb-0">No products with an expiration date on file.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>SKU</th><th>Product</th><th>Expires</th><th>Stock</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($expirationRows as $r): ?>
                    <tr><td class="small text-muted"><?= htmlspecialchars($r['sku']) ?></td><td><?= htmlspecialchars($r['name']) ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($r['expiration_date']))) ?></td><td><?= $r['current_stock'] ?></td>
                        <td><span class="badge-status <?= $expBadge[$r['status']] ?>"><?= str_replace('_', ' ', ucfirst($r['status'])) ?></span></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>

    <?php elseif ($report === 'my_sales'): ?>
        <h2 class="h6 mb-3">My Sales Report</h2>
        <?php if (!$mySalesRows): ?>
            <div class="empty-state py-4"><i class="bi bi-receipt"></i><p class="mb-0">No sales recorded in this period.</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Sale #</th><th>Date</th><th>Type</th><th>Total</th><th>Payment</th></tr></thead>
                <tbody><?php foreach ($mySalesRows as $r): ?>
                    <tr><td class="small"><?= htmlspecialchars($r['sale_number']) ?></td><td class="small"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($r['created_at']))) ?></td>
                        <td><?= ucfirst($r['customer_type']) ?></td><td>₱<?= number_format((float) $r['total_amount'], 2) ?></td>
                        <td class="small"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($r['payment_method'] ?? '—'))) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('analytics.view');

// ---------- Filter parsing & validation ----------
function validDateStr(?string $s): bool
{
    if (!$s) return false;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

$dateFrom = validDateStr($_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-d', strtotime('-29 days'));
$dateTo = validDateStr($_GET['date_to'] ?? '') ? $_GET['date_to'] : date('Y-m-d');
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$categoryId = ctype_digit((string) ($_GET['category_id'] ?? '')) ? (int) $_GET['category_id'] : null;
$brandId = ctype_digit((string) ($_GET['brand_id'] ?? '')) ? (int) $_GET['brand_id'] : null;
$customerType = in_array($_GET['customer_type'] ?? '', ['retail', 'reseller'], true) ? $_GET['customer_type'] : null;
// Schema note: `orders.channel` enum is physical/reseller/tiktok only — there is no
// "other/manual" channel yet (that's a later-stage addition). The filter below only
// offers real, working values rather than a no-op option.
$channel = in_array($_GET['channel'] ?? '', ['physical', 'reseller', 'tiktok'], true) ? $_GET['channel'] : null;
$granularity = in_array($_GET['granularity'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_GET['granularity'] : 'daily';
$skuSort = in_array($_GET['sku_sort'] ?? '', ['revenue', 'units'], true) ? $_GET['sku_sort'] : 'revenue';

$filters = [
    'date_from' => $dateFrom, 'date_to' => $dateTo,
    'category_id' => $categoryId, 'brand_id' => $brandId,
    'customer_type' => $customerType, 'channel' => $channel,
];

// Helper to build a link that preserves all current filters but overrides one param.
function withParam(array $overrides): string
{
    return '?' . http_build_query(array_merge($_GET, $overrides));
}

// ---------- Data (all from AnalyticsService — no ad-hoc SQL in this page) ----------
$kpis = AnalyticsService::kpis($filters);
$hasData = $kpis['has_data'];

$salesSeries = AnalyticsService::salesOverTime($filters, $granularity);
$categoryData = AnalyticsService::categoryBreakdown($filters);
$topSkuData = AnalyticsService::topSku($filters, $skuSort, 10);
$retailReseller = AnalyticsService::retailVsReseller($filters);
$stockData = AnalyticsService::stockAnalysis(['category_id' => $categoryId, 'brand_id' => $brandId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
$expirationData = AnalyticsService::expirationAnalysis();
$movement = AnalyticsService::fastSlowMoving(['category_id' => $categoryId, 'brand_id' => $brandId, 'date_from' => $dateFrom, 'date_to' => $dateTo], 10, 2, $stockData);

$stockStatusCounts = ['safe' => 0, 'low' => 0, 'critical' => 0];
foreach ($stockData as $s) { $stockStatusCounts[$s['stock_status']]++; }
$attentionProducts = array_values(array_filter($stockData, fn($s) => $s['stock_status'] !== 'safe' || in_array($s['recommendation'], ['Reorder Recommended', 'Urgent Reorder'], true)));

$categories = CategoryService::all(true);
$brands = BrandService::all(true);

$recBadge = ['Sufficient' => 'badge-success', 'Low Stock' => 'badge-warning', 'Reorder Recommended' => 'badge-warning', 'Urgent Reorder' => 'badge-danger'];
$expBadge = ['safe' => 'badge-success', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];

$pageTitle = 'Analytics Dashboard';
$activeNav = 'analytics';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title h4 mb-0">Analytics Dashboard</h1>
        <p class="text-muted mb-0 small">Shop performance, category, stock, and movement insights for Three Sisters' Olshoppe.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/analytics/basket.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-basket me-1"></i>Basket Analysis</a>
        <a href="<?= BASE_URL ?>/promotions/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags me-1"></i>Promo &amp; Bundle</a>
    </div>
</div>

<!-- ===================== FILTERS ===================== -->
<div class="ts-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small">From Date</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">To Date</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">Brand</label>
            <select name="brand_id" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $brandId === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">Customer Type</label>
            <select name="customer_type" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="retail" <?= $customerType === 'retail' ? 'selected' : '' ?>>Retail</option>
                <option value="reseller" <?= $customerType === 'reseller' ? 'selected' : '' ?>>Reseller</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">Sales Channel</label>
            <select name="channel" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="physical" <?= $channel === 'physical' ? 'selected' : '' ?>>Physical Store</option>
                <option value="reseller" <?= $channel === 'reseller' ? 'selected' : '' ?>>Reseller</option>
                <option value="tiktok" <?= $channel === 'tiktok' ? 'selected' : '' ?>>TikTok Shop</option>
            </select>
        </div>
        <div class="col-12 d-flex gap-2 mt-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            <a href="<?= BASE_URL ?>/analytics/index.php" class="btn btn-outline-secondary btn-sm">Reset Filters</a>
        </div>
    </form>
</div>

<!-- ===================== KPI CARDS ===================== -->
<div class="row g-3 mb-3">
    <?php
    $kpiCards = [
        ['label' => 'Total Sales', 'value' => '₱' . number_format($kpis['total_sales'], 2), 'icon' => 'bi-cash-stack'],
        ['label' => 'Transactions', 'value' => number_format($kpis['transactions']), 'icon' => 'bi-receipt'],
        ['label' => 'Units Sold', 'value' => number_format($kpis['units_sold']), 'icon' => 'bi-box-seam'],
        ['label' => 'Avg Basket Size', 'value' => number_format($kpis['avg_basket_size'], 2) . ' units', 'icon' => 'bi-basket'],
        ['label' => 'Avg Transaction Value', 'value' => '₱' . number_format($kpis['avg_transaction_value'], 2), 'icon' => 'bi-graph-up'],
        ['label' => 'Retail Revenue', 'value' => '₱' . number_format($kpis['retail_revenue'], 2), 'icon' => 'bi-person'],
        ['label' => 'Reseller Revenue', 'value' => '₱' . number_format($kpis['reseller_revenue'], 2), 'icon' => 'bi-shop'],
        ['label' => 'Low Stock', 'value' => number_format($kpis['low_stock_count']), 'icon' => 'bi-exclamation-triangle', 'accent' => 'warning', 'sub' => 'right now'],
        ['label' => 'Expiring Soon', 'value' => number_format($kpis['expiring_soon_count']), 'icon' => 'bi-hourglass-split', 'accent' => 'danger', 'sub' => 'within 30 days'],
    ];
    foreach ($kpiCards as $card):
        $iconStyle = isset($card['accent']) ? "background:var(--ts-{$card['accent']}-bg); color:var(--ts-{$card['accent']});" : '';
    ?>
        <div class="col-6 col-lg-4 col-xl-3">
            <div class="ts-card stat-card d-flex align-items-center gap-3 h-100">
                <div class="stat-icon" style="<?= $iconStyle ?>"><i class="bi <?= $card['icon'] ?>"></i></div>
                <div>
                    <div class="stat-label"><?= htmlspecialchars($card['label']) ?></div>
                    <div class="stat-value" style="font-size:1.3rem;"><?= $card['value'] ?></div>
                    <?php if (isset($card['sub'])): ?><div class="text-muted" style="font-size:0.7rem;"><?= $card['sub'] ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$hasData): ?>
    <div class="ts-card p-4 text-center mb-3">
        <i class="bi bi-bar-chart" style="font-size:1.8rem;color:var(--ts-text-muted);"></i>
        <p class="mt-2 mb-0 text-muted">No transaction data available for the selected period and filters. Try widening the date range or clearing filters.</p>
    </div>
<?php endif; ?>

<div class="alert alert-warning py-2 small d-none" id="chartsLibraryWarning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    The charting library could not be loaded (check your internet connection) — figures below are shown as tables instead.
</div>

<!-- ===================== SHOP PERFORMANCE ===================== -->
<div class="ts-card p-3 mb-3" id="shop-performance">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="h6 mb-0">Shop Performance — Sales Over Time</h2>
        <div class="btn-group btn-group-sm">
            <a href="<?= withParam(['granularity' => 'daily']) ?>#shop-performance" class="btn btn-<?= $granularity === 'daily' ? 'primary' : 'outline-secondary' ?>">Daily</a>
            <a href="<?= withParam(['granularity' => 'weekly']) ?>#shop-performance" class="btn btn-<?= $granularity === 'weekly' ? 'primary' : 'outline-secondary' ?>">Weekly</a>
            <a href="<?= withParam(['granularity' => 'monthly']) ?>#shop-performance" class="btn btn-<?= $granularity === 'monthly' ? 'primary' : 'outline-secondary' ?>">Monthly</a>
        </div>
    </div>
    <?php if (!$salesSeries): ?>
        <div class="empty-state py-4"><i class="bi bi-graph-up"></i><p class="mb-0">No transaction data available for the selected period.</p></div>
    <?php else: ?>
        <div class="alert alert-warning py-2 small d-none" id="salesChartWarning">
            <i class="bi bi-exclamation-triangle me-1"></i>The chart couldn't be drawn (see table below for the same data).
        </div>
        <div style="height:340px;"><canvas id="salesOverTimeChart"></canvas></div>
        <div class="table-responsive d-none mt-2" id="salesChartFallback">
            <table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>Revenue</th><th>Transactions</th></tr></thead>
                <tbody>
                <?php foreach ($salesSeries as $row): ?>
                    <tr><td><?= htmlspecialchars($row['period']) ?></td><td>₱<?= number_format($row['revenue'], 2) ?></td><td><?= number_format($row['transactions']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ===================== CATEGORY ANALYSIS + RETAIL/RESELLER ===================== -->
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="ts-card p-3 h-100">
            <h2 class="h6 mb-3">Category Analysis</h2>
            <?php if (!$categoryData): ?>
                <div class="empty-state py-4"><i class="bi bi-pie-chart"></i><p class="mb-0">Not enough transaction data to generate this analysis.</p></div>
            <?php else: ?>
                <div class="alert alert-warning py-2 small d-none" id="categoryChartsWarning">
                    <i class="bi bi-exclamation-triangle me-1"></i>The chart couldn't be drawn (see table below for the same data).
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6" style="height:260px;"><canvas id="categoryDoughnutChart"></canvas></div>
                    <div class="col-md-6" style="height:260px;"><canvas id="categoryBarChart"></canvas></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Category</th><th>Revenue</th><th>Units</th><th>Transactions</th><th>%</th></tr></thead>
                        <tbody>
                        <?php foreach ($categoryData as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['category_name']) ?></td>
                                <td>₱<?= number_format($c['revenue'], 2) ?></td>
                                <td><?= number_format($c['units_sold']) ?></td>
                                <td><?= number_format($c['transactions']) ?></td>
                                <td><?= $c['percentage'] ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ts-card p-3 h-100">
            <h2 class="h6 mb-3">Retail vs Reseller</h2>
            <?php if (!$hasData): ?>
                <div class="empty-state py-4"><i class="bi bi-people"></i><p class="mb-0">Not enough transaction data to generate this analysis.</p></div>
            <?php else: ?>
                <div class="alert alert-warning py-2 small d-none" id="retailResellerWarning">
                    <i class="bi bi-exclamation-triangle me-1"></i>The chart couldn't be drawn (see table below for the same data).
                </div>
                <div style="height:180px;" class="mb-3"><canvas id="retailResellerChart"></canvas></div>
                <table class="table table-sm mb-0">
                    <thead><tr><th></th><th>Retail</th><th>Reseller</th></tr></thead>
                    <tbody>
                        <tr><td class="text-muted">Revenue</td><td>₱<?= number_format($retailReseller['retail']['revenue'], 2) ?></td><td>₱<?= number_format($retailReseller['reseller']['revenue'], 2) ?></td></tr>
                        <tr><td class="text-muted">Transactions</td><td><?= number_format($retailReseller['retail']['transactions']) ?></td><td><?= number_format($retailReseller['reseller']['transactions']) ?></td></tr>
                        <tr><td class="text-muted">Units</td><td><?= number_format($retailReseller['retail']['units_sold']) ?></td><td><?= number_format($retailReseller['reseller']['units_sold']) ?></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===================== TOP SKU ===================== -->
<div class="ts-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="h6 mb-0">Top SKU by Sales</h2>
        <div class="btn-group btn-group-sm">
            <a href="<?= withParam(['sku_sort' => 'revenue']) ?>" class="btn btn-<?= $skuSort === 'revenue' ? 'primary' : 'outline-secondary' ?>">Top by Revenue</a>
            <a href="<?= withParam(['sku_sort' => 'units']) ?>" class="btn btn-<?= $skuSort === 'units' ? 'primary' : 'outline-secondary' ?>">Top by Units Sold</a>
        </div>
    </div>
    <?php if (!$topSkuData): ?>
        <div class="empty-state py-4"><i class="bi bi-trophy"></i><p class="mb-0">Not enough transaction data to generate this analysis.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>#</th><th>SKU</th><th>Product</th><th>Category</th><th>Units Sold</th><th>Revenue</th><th>Revenue %</th></tr></thead>
                <tbody>
                <?php foreach ($topSkuData as $s): ?>
                    <tr>
                        <td class="text-muted"><?= $s['rank'] ?></td>
                        <td class="small"><?= htmlspecialchars($s['sku']) ?></td>
                        <td><a href="<?= BASE_URL ?>/products/view.php?id=<?= $s['product_id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($s['name']) ?></a></td>
                        <td class="small text-muted"><?= htmlspecialchars($s['category_name']) ?></td>
                        <td><?= number_format($s['units_sold']) ?></td>
                        <td>₱<?= number_format($s['revenue'], 2) ?></td>
                        <td><?= $s['revenue_percentage'] ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ===================== STOCK ANALYSIS + EXPIRATION ===================== -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="ts-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h2 class="h6 mb-0">Stock Analysis</h2>
                <div class="d-flex gap-2 small">
                    <span class="badge-status badge-success">Safe: <?= $stockStatusCounts['safe'] ?></span>
                    <span class="badge-status badge-warning">Low: <?= $stockStatusCounts['low'] ?></span>
                    <span class="badge-status badge-danger">Critical: <?= $stockStatusCounts['critical'] ?></span>
                </div>
            </div>
            <p class="text-muted small">Period used for Average Daily Sales: <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?>.</p>
            <?php if (!$attentionProducts): ?>
                <div class="empty-state py-4"><i class="bi bi-check2-circle"></i><p class="mb-0">No products currently need attention.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Reorder Lvl</th><th>Avg Daily Sales</th><th>Est. Duration</th><th>Lead Time</th><th>Recommendation</th></tr></thead>
                        <tbody>
                        <?php foreach ($attentionProducts as $s): ?>
                            <tr>
                                <td><a href="<?= BASE_URL ?>/products/view.php?id=<?= $s['product_id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($s['name']) ?></a></td>
                                <td class="small text-muted"><?= htmlspecialchars($s['sku']) ?></td>
                                <td><?= $s['current_stock'] ?></td>
                                <td><?= $s['reorder_level'] ?></td>
                                <td><?= number_format($s['avg_daily_sales'], 2) ?></td>
                                <td><?= $s['estimated_days_remaining'] === null ? 'N/A' : $s['estimated_days_remaining'] . ' days' ?></td>
                                <td><?= $s['supplier_lead_time'] ?> days</td>
                                <td><span class="badge-status <?= $recBadge[$s['recommendation']] ?>"><?= htmlspecialchars($s['recommendation']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a href="<?= BASE_URL ?>/inventory/index.php" class="small d-inline-block mt-2">Manage Inventory →</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ts-card p-3 h-100">
            <h2 class="h6 mb-3">Expiration Analysis</h2>
            <div class="d-flex gap-2 small mb-3">
                <span class="badge-status badge-success">Safe: <?= $expirationData['safe'] ?></span>
                <span class="badge-status badge-warning">Soon: <?= $expirationData['expiring_soon'] ?></span>
                <span class="badge-status badge-danger">Expired: <?= $expirationData['expired'] ?></span>
            </div>
            <?php
            $alerting = array_values(array_filter($expirationData['items'], fn($i) => $i['status'] !== 'safe'));
            ?>
            <?php if (!$alerting): ?>
                <div class="empty-state py-3"><i class="bi bi-check2-circle"></i><p class="mb-0 small">No products expiring soon.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Product</th><th>Expires</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($alerting, 0, 8) as $item): ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars($item['name']) ?><div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($item['sku']) ?></div></td>
                                <td class="small"><?= htmlspecialchars(date('M j, Y', strtotime($item['expiration_date']))) ?></td>
                                <td><span class="badge-status <?= $expBadge[$item['status']] ?>"><?= str_replace('_', ' ', ucfirst($item['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a href="<?= BASE_URL ?>/inventory/expiration.php" class="small d-inline-block mt-2">View All →</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===================== FAST / SLOW MOVING ===================== -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="ts-card p-3 h-100">
            <h2 class="h6 mb-1">Fast-Moving Products</h2>
            <p class="text-muted small">Top sellers by units, <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?>. Based on actual completed sales — not a prediction.</p>
            <?php if (!$movement['fast_moving']): ?>
                <div class="empty-state py-3"><i class="bi bi-speedometer2"></i><p class="mb-0 small">Not enough transaction data to generate this analysis.</p></div>
            <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Product</th><th>Units Sold</th></tr></thead>
                    <tbody>
                    <?php foreach ($movement['fast_moving'] as $m): ?>
                        <tr><td class="small"><?= htmlspecialchars($m['name']) ?></td><td><?= $m['units_sold_period'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="ts-card p-3 h-100">
            <h2 class="h6 mb-1">Slow-Moving Products</h2>
            <p class="text-muted small">In-stock products with ≤2 units sold in the period — a fixed, transparent threshold, not a forecast.</p>
            <?php if (!$movement['slow_moving']): ?>
                <div class="empty-state py-3"><i class="bi bi-hourglass"></i><p class="mb-0 small">Not enough transaction data to generate this analysis.</p></div>
            <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Product</th><th>Units Sold</th><th>Current Stock</th></tr></thead>
                    <tbody>
                    <?php foreach ($movement['slow_moving'] as $m): ?>
                        <tr><td class="small"><?= htmlspecialchars($m['name']) ?></td><td><?= $m['units_sold_period'] ?></td><td><?= $m['current_stock'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $localChartJsExists = file_exists(__DIR__ . '/../assets/js/vendor/chart.umd.min.js'); ?>
<?php if ($localChartJsExists): ?>
    <!-- Local copy present — fastest path, works with no internet at all. -->
    <script src="<?= BASE_URL ?>/assets/js/vendor/chart.umd.min.js"></script>
<?php endif; ?>
<script>
function initAnalyticsCharts() {
    const chartColors = ['#C98A93', '#B06E78', '#3E7CB1', '#3E8E5A', '#B98A2E', '#C0473F', '#8A7F7A', '#D8A7AE'];

    // Chart.js failing to load (CDN blocked/unreachable, offline XAMPP box, etc.) is a
    // real, distinguishable failure mode from "no data" — the PHP side already renders
    // its own "not enough data" empty states server-side and never emits chart-init code
    // for those cases at all, so if we get here at all there WAS data. Anything that goes
    // wrong past this point is a rendering problem, not a data problem, and is shown as
    // exactly that rather than silently leaving a blank box.
    const chartJsLoaded = typeof Chart !== 'undefined';

    function showFallback(warningId, canvasId, fallbackId) {
        const warning = document.getElementById(warningId);
        const canvas = document.getElementById(canvasId);
        const fallback = document.getElementById(fallbackId);
        if (warning) warning.classList.remove('d-none');
        if (canvas) canvas.classList.add('d-none');
        if (fallback) fallback.classList.remove('d-none');
    }

    if (!chartJsLoaded) {
        const globalWarning = document.getElementById('chartsLibraryWarning');
        if (globalWarning) globalWarning.classList.remove('d-none');
        showFallback('salesChartWarning', 'salesOverTimeChart', 'salesChartFallback');
        return; // nothing below can run without the Chart constructor
    }

    // Each chart is isolated in its own try/catch: a bad value or a missing canvas in
    // one chart must never stop the others from attempting to render (a single uncaught
    // error in a synchronous script block halts everything after it otherwise).

    <?php if ($salesSeries): ?>
    try {
        const canvas = document.getElementById('salesOverTimeChart');
        if (!canvas) throw new Error('salesOverTimeChart canvas not found');
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($salesSeries, 'period')) ?>,
                datasets: [
                    {
                        label: 'Revenue (₱)', data: <?= json_encode(array_map('floatval', array_column($salesSeries, 'revenue'))) ?>,
                        borderColor: '#C98A93', backgroundColor: 'rgba(201,138,147,0.15)', fill: true, tension: 0.3, yAxisID: 'y'
                    },
                    {
                        label: 'Transactions', data: <?= json_encode(array_map('intval', array_column($salesSeries, 'transactions'))) ?>,
                        borderColor: '#3E7CB1', backgroundColor: 'rgba(62,124,177,0.1)', fill: false, tension: 0.3, yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { position: 'left', title: { display: true, text: 'Revenue (₱)' } },
                    y1: { position: 'right', title: { display: true, text: 'Transactions' }, grid: { drawOnChartArea: false } }
                }
            }
        });
    } catch (e) {
        console.error('Sales Over Time chart failed:', e);
        showFallback('salesChartWarning', 'salesOverTimeChart', 'salesChartFallback');
    }
    <?php endif; ?>

    <?php if ($categoryData): ?>
    try {
        const canvas = document.getElementById('categoryDoughnutChart');
        if (!canvas) throw new Error('categoryDoughnutChart canvas not found');
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($categoryData, 'category_name')) ?>,
                datasets: [{ data: <?= json_encode(array_map('floatval', array_column($categoryData, 'revenue'))) ?>, backgroundColor: chartColors }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } }
        });
    } catch (e) {
        console.error('Category Revenue chart failed:', e);
        const c = document.getElementById('categoryDoughnutChart'); if (c) c.classList.add('d-none');
        const w = document.getElementById('categoryChartsWarning'); if (w) w.classList.remove('d-none');
    }

    try {
        const canvas = document.getElementById('categoryBarChart');
        if (!canvas) throw new Error('categoryBarChart canvas not found');
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($categoryData, 'category_name')) ?>,
                datasets: [{ label: 'Units Sold', data: <?= json_encode(array_map('intval', array_column($categoryData, 'units_sold'))) ?>, backgroundColor: '#C98A93' }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    } catch (e) {
        console.error('Category Units chart failed:', e);
        const c = document.getElementById('categoryBarChart'); if (c) c.classList.add('d-none');
        const w = document.getElementById('categoryChartsWarning'); if (w) w.classList.remove('d-none');
    }
    <?php endif; ?>

    <?php if ($hasData): ?>
    try {
        const canvas = document.getElementById('retailResellerChart');
        if (!canvas) throw new Error('retailResellerChart canvas not found');
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: ['Revenue (₱)'],
                datasets: [
                    { label: 'Retail', data: [<?= json_encode((float) $retailReseller['retail']['revenue']) ?>], backgroundColor: '#C98A93' },
                    { label: 'Reseller', data: [<?= json_encode((float) $retailReseller['reseller']['revenue']) ?>], backgroundColor: '#3E7CB1' }
                ]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    } catch (e) {
        console.error('Retail vs Reseller chart failed:', e);
        const c = document.getElementById('retailResellerChart'); if (c) c.classList.add('d-none');
        const w = document.getElementById('retailResellerWarning'); if (w) w.classList.remove('d-none');
    }
    <?php endif; ?>
}

<?php if ($localChartJsExists): ?>
// Local vendor <script src> above is a normal blocking tag, so Chart already
// exists by the time this line runs — init directly, no network loading needed.
initAnalyticsCharts();
<?php else: ?>
// No local copy — try CDN sources in order. Each dynamically-created <script>
// only starts loading after the previous one's onerror fires, so at most one
// CDN script ever actually loads (never both, even if both would have worked).
// initAnalyticsCharts() runs exactly once: either from the first onload that
// fires, or — if every source fails — after the list is exhausted, at which
// point Chart is still undefined and the function safely shows the existing
// warning banners / table fallbacks instead of a chart.
(function loadChartJsThenInit(sources, index) {
    if (index >= sources.length) {
        initAnalyticsCharts();
        return;
    }
    const script = document.createElement('script');
    script.src = sources[index];
    script.onload = function () { initAnalyticsCharts(); };
    script.onerror = function () { loadChartJsThenInit(sources, index + 1); };
    document.head.appendChild(script);
})([
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'
], 0);
<?php endif; ?>
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

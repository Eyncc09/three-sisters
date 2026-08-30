<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('promotions.manage');

$duringRows = PromotionService::allDuringPerformance();

$best = null; // highest revenue
$mostUsed = null; // highest transaction count
$totalDiscount = 0.0;
$totalRevenue = 0.0;
foreach ($duringRows as $r) {
    if ($best === null || (float) $r['revenue'] > (float) $best['revenue']) $best = $r;
    if ($mostUsed === null || (int) $r['transactions_count'] > (int) $mostUsed['transactions_count']) $mostUsed = $r;
    $totalDiscount += (float) $r['discount_given'];
    $totalRevenue += (float) $r['revenue'];
}

// ---------- Promo Opportunity (rule-based, from existing AnalyticsService — no AI) ----------
$today = date('Y-m-d');
$last30From = date('Y-m-d', strtotime('-29 days'));
$opportunityFilters = ['date_from' => $last30From, 'date_to' => $today];

$topSellers = AnalyticsService::topSku($opportunityFilters, 'units', 3);
$stockData = AnalyticsService::stockAnalysis($opportunityFilters);
$movement = AnalyticsService::fastSlowMoving($opportunityFilters, 5, 2, $stockData);
$expiration = AnalyticsService::expirationAnalysis();

$opportunities = [];
foreach ($topSellers as $s) {
    $opportunities[] = ['icon' => 'bi-fire', 'accent' => 'success', 'text' => "\"{$s['name']}\" is a high-selling product ({$s['units_sold']} units in the last 30 days) — potential promotion opportunity to feature it further."];
}
foreach ($movement['slow_moving'] as $s) {
    $opportunities[] = ['icon' => 'bi-hourglass', 'accent' => 'warning', 'text' => "\"{$s['name']}\" has low sales movement ({$s['units_sold_period']} units in 30 days) with {$s['current_stock']} units in stock — a candidate for promotional review."];
}
foreach (array_filter($expiration['items'], fn($i) => $i['status'] === 'expiring_soon') as $i) {
    $opportunities[] = ['icon' => 'bi-clock-history', 'accent' => 'danger', 'text' => "\"{$i['name']}\" is expiring on " . date('M j, Y', strtotime($i['expiration_date'])) . " — may require a promotional strategy to move remaining stock."];
}

$pageTitle = 'Promo Performance';
$activeNav = 'promotions';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <nav class="breadcrumb"><a href="<?= BASE_URL ?>/promotions/index.php" class="text-decoration-none">Promotions</a>&nbsp;/&nbsp;Performance</nav>
    <h1 class="page-title h4 mb-0">Promo Performance</h1>
    <p class="text-muted mb-0 small">Across all promotions with calculated performance data.</p>
</div>

<?php if (!$duringRows): ?>
    <div class="ts-card p-4 text-center mb-3">
        <i class="bi bi-graph-up" style="font-size:1.8rem;color:var(--ts-text-muted);"></i>
        <p class="mt-2 mb-0 text-muted">No promotion performance has been calculated yet. Open a promotion and click "Calculate Performance" to populate this page.</p>
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="ts-card stat-card">
                <div class="stat-label">Best-Performing (Revenue)</div>
                <div class="stat-value" style="font-size:1.1rem;"><?= htmlspecialchars($best['promotion_name']) ?></div>
                <div class="text-muted small">₱<?= number_format((float) $best['revenue'], 2) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="ts-card stat-card">
                <div class="stat-label">Most-Used (Transactions)</div>
                <div class="stat-value" style="font-size:1.1rem;"><?= htmlspecialchars($mostUsed['promotion_name']) ?></div>
                <div class="text-muted small"><?= (int) $mostUsed['transactions_count'] ?> transactions</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="ts-card stat-card">
                <div class="stat-label">Total Revenue (During Promos)</div>
                <div class="stat-value" style="font-size:1.3rem;">₱<?= number_format($totalRevenue, 2) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="ts-card stat-card">
                <div class="stat-label">Total Discount Given (est.)</div>
                <div class="stat-value" style="font-size:1.3rem;">₱<?= number_format($totalDiscount, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="ts-card p-3 mb-3">
        <h2 class="h6 mb-3">All Promotions — During-Period Performance</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Promotion</th><th>Discount</th><th>Period</th><th>Transactions</th><th>Units Sold</th><th>Revenue</th><th>Discount Given (est.)</th></tr></thead>
                <tbody>
                <?php foreach ($duringRows as $r): ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>/promotions/view.php?id=<?= $r['promotion_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($r['promotion_name']) ?></a></td>
                        <td class="small"><?= $r['discount_type'] === 'percentage' ? number_format((float) $r['discount_value'], 0) . '%' : '₱' . number_format((float) $r['discount_value'], 2) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars(date('M j', strtotime($r['start_date']))) ?> – <?= htmlspecialchars(date('M j, Y', strtotime($r['end_date']))) ?></td>
                        <td><?= (int) $r['transactions_count'] ?></td>
                        <td><?= (int) $r['units_sold'] ?></td>
                        <td>₱<?= number_format((float) $r['revenue'], 2) ?></td>
                        <td>₱<?= number_format((float) $r['discount_given'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="ts-card p-3">
    <h2 class="h6 mb-1">Promotional Opportunities</h2>
    <p class="text-muted small">Rule-based observations from the last 30 days of actual sales and current inventory — not a prediction of customer behavior.</p>
    <?php if (!$opportunities): ?>
        <div class="empty-state py-3"><i class="bi bi-lightbulb"></i><p class="mb-0 small">Not enough transaction data to generate opportunities right now.</p></div>
    <?php else: ?>
        <ul class="list-unstyled mb-0">
            <?php foreach ($opportunities as $o): ?>
                <li class="d-flex align-items-start gap-2 py-2 border-bottom">
                    <i class="bi <?= $o['icon'] ?> mt-1" style="color:var(--ts-<?= $o['accent'] ?>);"></i>
                    <span class="small"><?= htmlspecialchars($o['text']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

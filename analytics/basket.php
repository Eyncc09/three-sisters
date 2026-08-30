<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('analytics.view');

function validDateStr(?string $s): bool
{
    if (!$s) return false;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

$dateFrom = validDateStr($_POST['date_from'] ?? $_GET['date_from'] ?? '') ? ($_POST['date_from'] ?? $_GET['date_from']) : date('Y-m-d', strtotime('-89 days'));
$dateTo = validDateStr($_POST['date_to'] ?? $_GET['date_to'] ?? '') ? ($_POST['date_to'] ?? $_GET['date_to']) : date('Y-m-d');
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$categoryId = ctype_digit((string) ($_POST['category_id'] ?? $_GET['category_id'] ?? '')) ? (int) ($_POST['category_id'] ?? $_GET['category_id']) : null;

$minSupportPct = is_numeric($_POST['min_support'] ?? '') ? (float) $_POST['min_support'] : 1.0;
$minConfidencePct = is_numeric($_POST['min_confidence'] ?? '') ? (float) $_POST['min_confidence'] : 10.0;
$minLift = is_numeric($_POST['min_lift'] ?? '') ? (float) $_POST['min_lift'] : 1.0;
$maxResults = ctype_digit((string) ($_POST['max_results'] ?? '')) ? (int) $_POST['max_results'] : 20;

// UI collects support/confidence as percentages (friendlier for the Owner) — convert to 0..1 fractions here.
$minSupportPct = min(max($minSupportPct, 0.0), 100.0);
$minConfidencePct = min(max($minConfidencePct, 0.0), 100.0);
$minLift = max($minLift, 0.0);
$maxResults = min(max($maxResults, 1), 100);

$filters = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'category_id' => $categoryId];

$result = null;
$runError = null;
$hasRun = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_analysis') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $runError = 'Your session expired. Please try again.';
    } else {
        $hasRun = true;
        try {
            $result = BasketAnalysisService::run($filters, $minSupportPct / 100, $minConfidencePct / 100, $minLift, $maxResults);
        } catch (RuntimeException $e) {
            $runError = $e->getMessage();
        }
    }
}

$categories = CategoryService::all(true);
$recBadge = ['Strong Bundle Candidate' => 'badge-success', 'Potential Bundle' => 'badge-warning', 'Weak Association' => 'badge-neutral'];
$stockBadge = ['safe' => 'badge-success', 'low' => 'badge-warning', 'critical' => 'badge-danger', 'expiring_soon' => 'badge-warning', 'expired' => 'badge-danger'];

$bundleCandidates = $result ? array_values(array_filter($result['pairs'], fn($p) => $p['recommendation'] !== 'Weak Association')) : [];

$pageTitle = 'Basket Analysis';
$activeNav = 'analytics';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <nav class="breadcrumb"><a href="<?= BASE_URL ?>/analytics/index.php" class="text-decoration-none">Analytics</a>&nbsp;/&nbsp;Basket Analysis</nav>
        <h1 class="page-title h4 mb-0">Basket Analysis — Frequent Item Mining</h1>
        <p class="text-muted mb-0 small">Finds product pairs frequently bought together in actual completed transactions. This is transaction-pattern statistics, not AI prediction.</p>
    </div>
    <a href="<?= BASE_URL ?>/promotions/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags me-1"></i>Promo &amp; Bundle</a>
</div>

<!-- ===================== METRIC EXPLANATIONS ===================== -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small"><span class="lang-en">Explanations</span><span class="lang-tl d-none">Paliwanag</span></span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Language toggle">
        <button type="button" class="btn btn-secondary lang-btn" data-lang="en">EN</button>
        <button type="button" class="btn btn-outline-secondary lang-btn" data-lang="tl">TL</button>
    </div>
</div>
<div class="row g-3 mb-2">
    <div class="col-md-4">
        <div class="ts-card p-3 h-100">
            <strong class="small">Support</strong>
            <p class="text-muted small mb-1 mt-1">
                <span class="lang-en">How often these two products are bought together.</span>
                <span class="lang-tl d-none">Gaano kadalas binibili nang magkasama ang dalawang produkto.</span>
            </p>
            <p class="text-muted mb-0" style="font-size:0.75rem;">
                <span class="lang-en">Higher = the pair appears together more often.</span>
                <span class="lang-tl d-none">Mas mataas = mas madalas silang binibili nang magkasama.</span>
            </p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="ts-card p-3 h-100">
            <strong class="small">Confidence</strong>
            <p class="text-muted small mb-1 mt-1">
                <span class="lang-en">How often Product B is bought when Product A is bought.</span>
                <span class="lang-tl d-none">Gaano kadalas binibili ang Product B kapag binibili ang Product A.</span>
            </p>
            <p class="text-muted mb-0" style="font-size:0.75rem;">
                <span class="lang-en">Higher = B is more often bought when A is bought.</span>
                <span class="lang-tl d-none">Mas mataas = mas madalas binibili ang B kapag binibili ang A.</span>
            </p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="ts-card p-3 h-100">
            <strong class="small">Lift</strong>
            <p class="text-muted small mb-1 mt-1">
                <span class="lang-en">Shows how much more often the two products are bought together than expected by chance. Shows association, not cause.</span>
                <span class="lang-tl d-none">Ipinapakita kung gaano kadalas binibili nang magkasama ang dalawang produkto kumpara sa inaasahan. Ugnayan lang ito, hindi sanhi.</span>
            </p>
            <p class="text-muted mb-0" style="font-size:0.75rem;">
                <span class="lang-en">Lift &gt; 1 = Bought together more than expected.<br>Lift = 1 = About as expected.<br>Lift &lt; 1 = Bought together less than expected.</span>
                <span class="lang-tl d-none">Lift &gt; 1 = Mas madalas silang binibili nang magkasama kaysa sa inaasahan.<br>Lift = 1 = Halos ayon sa inaasahan.<br>Lift &lt; 1 = Mas bihira silang binibili nang magkasama kaysa sa inaasahan.</span>
            </p>
        </div>
    </div>
</div>

<!-- ===================== HOW TO USE THIS (collapsible) ===================== -->
<div class="ts-card p-0 mb-3">
    <button class="btn btn-link text-decoration-none w-100 text-start p-3 d-flex justify-content-between align-items-center" type="button" data-bs-toggle="collapse" data-bs-target="#howToUseCollapse" aria-expanded="false" aria-controls="howToUseCollapse">
        <span class="small fw-semibold text-dark">
            <i class="bi bi-question-circle me-1"></i>
            <span class="lang-en">How to use this</span><span class="lang-tl d-none">Paano gamitin ito</span>
        </span>
        <i class="bi bi-chevron-down small text-muted"></i>
    </button>
    <div class="collapse" id="howToUseCollapse">
        <div class="px-3 pb-3">
            <ol class="small text-muted mb-0 ps-3">
                <li>
                    <span class="lang-en">Look for higher Support to find commonly purchased pairs.</span>
                    <span class="lang-tl d-none">Tingnan ang mataas na Support para makita ang mga produktong madalas bilhin nang magkasama.</span>
                </li>
                <li>
                    <span class="lang-en">Check Confidence to see how often one product is bought with the other.</span>
                    <span class="lang-tl d-none">Tingnan ang Confidence para malaman kung gaano kadalas binibili ang isang produkto kasama ng isa pa.</span>
                </li>
                <li>
                    <span class="lang-en">Check Lift to see whether the pair occurs more often than expected.</span>
                    <span class="lang-tl d-none">Tingnan ang Lift para malaman kung mas madalas silang binibili nang magkasama kaysa sa inaasahan.</span>
                </li>
                <li>
                    <span class="lang-en">Review stock before creating a promotion or bundle.</span>
                    <span class="lang-tl d-none">Suriin muna ang stock bago gumawa ng promotion o bundle.</span>
                </li>
            </ol>
        </div>
    </div>
</div>

<!-- ===================== FILTERS ===================== -->
<div class="ts-card p-3 mb-3">
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_analysis">
        <div class="row g-2 align-items-end">
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
            <div class="col-6 col-md-1">
                <label class="form-label small">Min Support %</label>
                <input type="number" name="min_support" step="0.1" min="0" max="100" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $minSupportPct) ?>">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small">Min Conf. %</label>
                <input type="number" name="min_confidence" step="1" min="0" max="100" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $minConfidencePct) ?>">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small">Min Lift</label>
                <input type="number" name="min_lift" step="0.1" min="0" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $minLift) ?>">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small">Max Results</label>
                <input type="number" name="max_results" step="1" min="1" max="100" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $maxResults) ?>">
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-play-fill me-1"></i>Run Basket Analysis</button>
            </div>
        </div>
    </form>
</div>

<?php if ($runError): ?>
    <div class="alert alert-danger py-2 mb-3"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($runError) ?></div>
<?php elseif ($hasRun && $result): ?>

    <?php if ($result['total_transactions'] === 0): ?>
        <div class="ts-card p-4 text-center mb-3">
            <i class="bi bi-basket" style="font-size:1.8rem;color:var(--ts-text-muted);"></i>
            <p class="mt-2 mb-0 text-muted">No transaction data available for basket analysis in the selected period/filters.</p>
        </div>
    <?php elseif (!$result['pairs']): ?>
        <div class="ts-card p-4 text-center mb-3">
            <i class="bi bi-filter-circle" style="font-size:1.8rem;color:var(--ts-text-muted);"></i>
            <p class="mt-2 mb-0 text-muted">Analyzed <?= number_format($result['total_transactions']) ?> completed transactions — no product combinations meet the selected thresholds. Try lowering Min Support/Confidence/Lift.</p>
        </div>
    <?php else: ?>

        <!-- ===================== BUNDLE OPPORTUNITY ===================== -->
        <?php if ($bundleCandidates): ?>
        <div class="ts-card p-3 mb-3">
            <h2 class="h6 mb-1">Bundle Opportunity</h2>
            <p class="text-muted small">Candidates worth the Owner's review — nothing here is created automatically.</p>
            <div class="row g-3">
                <?php foreach ($bundleCandidates as $c): ?>
                    <?php $a = $c['product_a_info']; $b = $c['product_b_info']; ?>
                    <div class="col-md-6">
                        <div class="ts-card p-3 h-100" style="border-color: <?= $c['recommendation'] === 'Strong Bundle Candidate' ? 'var(--ts-success)' : 'var(--ts-warning)' ?>;">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <strong class="small"><?= htmlspecialchars($a['name']) ?> + <?= htmlspecialchars($b['name']) ?></strong>
                                <span class="badge-status <?= $recBadge[$c['recommendation']] ?>"><?= htmlspecialchars($c['recommendation']) ?></span>
                            </div>
                            <div class="row small text-muted mb-2">
                                <div class="col-4">Together: <strong class="text-dark"><?= (int) $c['transactions_together'] ?></strong></div>
                                <div class="col-4">Support: <strong class="text-dark"><?= number_format($c['support'] * 100, 2) ?>%</strong></div>
                                <div class="col-4">Lift: <strong class="text-dark"><?= number_format($c['lift'], 2) ?></strong></div>
                            </div>
                            <table class="table table-sm mb-2">
                                <thead><tr><th></th><th>Stock</th><th>Movement</th><th>Expiration</th><th>Est. Duration</th></tr></thead>
                                <tbody>
                                    <tr>
                                        <td class="small"><?= htmlspecialchars($a['name']) ?></td>
                                        <td><span class="badge-status <?= $stockBadge[$a['stock_status']] ?>"><?= $a['current_stock'] ?></span></td>
                                        <td class="small"><?= $a['movement'] ?></td>
                                        <td><?php if ($a['expiration_status']): ?><span class="badge-status <?= $stockBadge[$a['expiration_status']] ?>"><?= str_replace('_', ' ', ucfirst($a['expiration_status'])) ?></span><?php else: ?><span class="text-muted small">N/A</span><?php endif; ?></td>
                                        <td class="small"><?= $a['estimated_days_remaining'] === null ? 'N/A' : $a['estimated_days_remaining'] . 'd' ?></td>
                                    </tr>
                                    <tr>
                                        <td class="small"><?= htmlspecialchars($b['name']) ?></td>
                                        <td><span class="badge-status <?= $stockBadge[$b['stock_status']] ?>"><?= $b['current_stock'] ?></span></td>
                                        <td class="small"><?= $b['movement'] ?></td>
                                        <td><?php if ($b['expiration_status']): ?><span class="badge-status <?= $stockBadge[$b['expiration_status']] ?>"><?= str_replace('_', ' ', ucfirst($b['expiration_status'])) ?></span><?php else: ?><span class="text-muted small">N/A</span><?php endif; ?></td>
                                        <td class="small"><?= $b['estimated_days_remaining'] === null ? 'N/A' : $b['estimated_days_remaining'] . 'd' ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <?php
                                $zeroStock = (int) $a['current_stock'] === 0 || (int) $b['current_stock'] === 0;
                                $lowStock = in_array($a['stock_status'], ['low', 'critical'], true) || in_array($b['stock_status'], ['low', 'critical'], true);
                                $criticalBlock = $a['stock_status'] === 'critical' || $b['stock_status'] === 'critical'
                                    || in_array($a['expiration_status'], ['expired'], true) || in_array($b['expiration_status'], ['expired'], true);
                            ?>
                            <?php if ($criticalBlock): ?>
                                <div class="alert alert-warning py-1 px-2 small mb-2">⚠ One of these products is critically low on stock or expired — review inventory before promoting.</div>
                            <?php endif; ?>
                            <div class="alert alert-info py-1 px-2 small mb-2">
                                <strong><span class="lang-en">What should I do?</span><span class="lang-tl d-none">Ano ang dapat gawin?</span></strong>
                                <?php if ($zeroStock): ?>
                                    <div><span class="lang-en">Restock before promoting.</span><span class="lang-tl d-none">Mag-restock bago i-promote.</span></div>
                                <?php elseif ($lowStock): ?>
                                    <div><span class="lang-en">Consider restocking first.</span><span class="lang-tl d-none">Mas mabuting mag-restock muna.</span></div>
                                <?php else: ?>
                                    <div><span class="lang-en">Consider creating a bundle.</span><span class="lang-tl d-none">Maaaring gumawa ng bundle.</span></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:0.68rem;">
                                    <span class="lang-en">Rule-based suggestion from current stock — not an AI prediction.</span>
                                    <span class="lang-tl d-none">Rule-based na mungkahi galing sa kasalukuyang stock — hindi ito AI prediction.</span>
                                </div>
                            </div>
                            <a href="<?= BASE_URL ?>/promotions/add.php?product_ids[]=<?= $a['id'] ?>&product_ids[]=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-arrow-right-circle me-1"></i>Review Bundle in Promotions
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===================== FULL RESULTS TABLE ===================== -->
        <div class="ts-card p-3">
            <h2 class="h6 mb-1">All Product Pairs</h2>
            <p class="text-muted small">Analyzed <?= number_format($result['total_transactions']) ?> completed transactions from <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?>.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th><th>Product A</th><th>Product B</th><th>Support</th>
                            <th>Conf. A→B</th><th>Conf. B→A</th><th>Lift</th><th>Together</th><th>Recommendation</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['pairs'] as $i => $p): ?>
                        <?php $a = $p['product_a_info']; $b = $p['product_b_info']; ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td class="small"><?= htmlspecialchars($a['name']) ?><div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($a['sku']) ?> — <?= htmlspecialchars($a['category_name']) ?></div></td>
                            <td class="small"><?= htmlspecialchars($b['name']) ?><div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($b['sku']) ?> — <?= htmlspecialchars($b['category_name']) ?></div></td>
                            <td><?= number_format($p['support'] * 100, 2) ?>%</td>
                            <td><?= number_format($p['confidence_a_to_b'] * 100, 2) ?>%</td>
                            <td><?= number_format($p['confidence_b_to_a'] * 100, 2) ?>%</td>
                            <td><?= number_format($p['lift'], 2) ?></td>
                            <td><?= (int) $p['transactions_together'] ?></td>
                            <td><span class="badge-status <?= $recBadge[$p['recommendation']] ?>"><?= htmlspecialchars($p['recommendation']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php elseif (!$hasRun): ?>
    <div class="ts-card p-4 text-center">
        <i class="bi bi-play-circle" style="font-size:1.8rem;color:var(--ts-text-muted);"></i>
        <p class="mt-2 mb-0 text-muted">Set your filters above and click "Run Basket Analysis" to analyze actual completed transactions.</p>
    </div>
<?php endif; ?>

<p class="text-muted mt-3" style="font-size:0.72rem;">
    Note: analysis is limited to product PAIRS (not larger combinations) and returns at most 100 pairs, ranked by strength of association — a deliberate scope limit to keep results explainable and fast at this catalog size.
</p>
<script>
(function () {
    var STORAGE_KEY = 'ts_basket_lang';

    function applyLang(lang) {
        var enEls = document.querySelectorAll('.lang-en');
        var tlEls = document.querySelectorAll('.lang-tl');
        for (var i = 0; i < enEls.length; i++) { enEls[i].classList.toggle('d-none', lang !== 'en'); }
        for (var j = 0; j < tlEls.length; j++) { tlEls[j].classList.toggle('d-none', lang !== 'tl'); }

        var btns = document.querySelectorAll('.lang-btn');
        for (var k = 0; k < btns.length; k++) {
            var isActive = btns[k].getAttribute('data-lang') === lang;
            btns[k].classList.toggle('btn-secondary', isActive);
            btns[k].classList.toggle('btn-outline-secondary', !isActive);
        }

        try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) { /* localStorage unavailable — language just won't persist */ }
    }

    var buttons = document.querySelectorAll('.lang-btn');
    for (var b = 0; b < buttons.length; b++) {
        buttons[b].addEventListener('click', function () {
            applyLang(this.getAttribute('data-lang'));
        });
    }

    var saved = 'en';
    try { saved = localStorage.getItem(STORAGE_KEY) || 'en'; } catch (e) { /* default to English */ }
    applyLang(saved);
})();
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

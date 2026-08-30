<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('pos.use');

$products = db()->query(
    "SELECT p.id, p.sku, p.name, p.selling_price, p.category_id, COALESCE(i.quantity_on_hand,0) AS stock
     FROM products p LEFT JOIN inventory i ON i.product_id = p.id
     WHERE p.status = 'active' ORDER BY p.name ASC"
)->fetchAll();

// Stage 4C: attach active-promotion info for the badge shown on each product card.
// This is DISPLAY ONLY — pos/checkout.php independently recomputes the real discount
// server-side from the database and never trusts anything the client sends about promotions.
$activePromosByProduct = PromotionService::activePromotionsForProducts(array_column($products, 'id'));
foreach ($products as &$prod) {
    $promo = $activePromosByProduct[(int) $prod['id']] ?? null;
    $prod['promo_label'] = $promo
        ? ($promo['discount_type'] === 'percentage'
            ? number_format((float) $promo['discount_value'], 0) . '% OFF'
            : '₱' . number_format((float) $promo['discount_value'], 2) . ' OFF')
        : null;
}
unset($prod);

$categories = CategoryService::all(true);

$customers = db()->query("SELECT id, full_name, phone FROM customers ORDER BY full_name ASC LIMIT 500")->fetchAll();
$resellers = db()->query("SELECT id, full_name, business_name FROM resellers WHERE status = 'active' ORDER BY full_name ASC LIMIT 500")->fetchAll();

$recentSales = OrderService::recentSales(hasRole(ROLE_STAFF) ? (int) $_SESSION['user_id'] : null, 8);

$pageTitle = 'POS';
$activeNav = 'pos';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Point of Sale</h1>
    <p class="text-muted mb-0 small">Search products, build the cart, and complete the sale.</p>
</div>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2"><?= htmlspecialchars($_GET['error']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT: product search + grid -->
    <div class="col-lg-8">
        <div class="ts-card p-3 mb-3">
            <div class="row g-2">
                <div class="col-md-6">
                    <input type="text" id="posSearch" class="form-control form-control-sm" placeholder="Search product name or SKU...">
                </div>
                <div class="col-md-6">
                    <div class="d-flex flex-wrap gap-1" id="posCategoryChips">
                        <button type="button" class="btn btn-sm btn-primary category-chip" data-cat="">All</button>
                        <?php foreach ($categories as $c): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary category-chip" data-cat="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-2" id="posProductGrid" style="max-height: 62vh; overflow-y: auto;"></div>
        <div class="empty-state d-none" id="posNoResults"><i class="bi bi-search"></i><p class="mb-0">No products match your search.</p></div>
    </div>

    <!-- RIGHT: cart + checkout -->
    <div class="col-lg-4">
        <div class="ts-card p-3" style="position: sticky; top: 80px;">
            <h2 class="h6 mb-2"><i class="bi bi-cart3 me-1"></i>Cart</h2>
            <div id="posCartEmpty" class="empty-state py-4"><i class="bi bi-cart-x"></i><p class="mb-0 small">Cart is empty. Click a product to add it.</p></div>
            <div id="posCartList" class="d-none"></div>

            <hr>
            <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Subtotal</span><span id="posSubtotal">₱0.00</span></div>
            <div class="d-flex justify-content-between align-items-center small mb-1">
                <span class="text-muted">Discount (₱)</span>
                <input type="number" id="posDiscount" name="discount_amount" class="form-control form-control-sm text-end" style="width:110px;" min="0" step="0.01" value="0">
            </div>
            <p class="text-muted mb-2" style="font-size:0.7rem;">🏷 badged items have an active promotion — it's applied automatically at checkout on top of any discount you enter here.</p>
            <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span>Total</span><span id="posTotal">₱0.00</span></div>

            <form id="checkoutForm" method="POST" action="<?= BASE_URL ?>/pos/checkout.php" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="cart_json" id="cartJsonInput">

                <label class="form-label small fw-semibold">Customer Type</label>
                <div class="btn-group w-100 mb-2" role="group">
                    <input type="radio" class="btn-check" name="customer_type" id="ctypeRetail" value="retail" checked>
                    <label class="btn btn-outline-primary btn-sm" for="ctypeRetail">Retail</label>
                    <input type="radio" class="btn-check" name="customer_type" id="ctypeReseller" value="reseller">
                    <label class="btn btn-outline-primary btn-sm" for="ctypeReseller">Reseller</label>
                </div>

                <div class="mb-2" id="retailCustomerWrap">
                    <label class="form-label small">Customer (optional — walk-in if blank)</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="">— Walk-in —</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?><?= $c['phone'] ? ' — ' . htmlspecialchars($c['phone']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2 d-none" id="resellerWrap">
                    <label class="form-label small">Reseller <span class="text-danger">*</span></label>
                    <select name="reseller_id" id="resellerSelect" class="form-select form-select-sm">
                        <option value="">— Select reseller —</option>
                        <?php foreach ($resellers as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['full_name']) ?><?= $r['business_name'] ? ' (' . htmlspecialchars($r['business_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label class="form-label small fw-semibold mt-1">Payment Method</label>
                <select name="payment_method" id="paymentMethod" class="form-select form-select-sm mb-2">
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>

                <div id="cashWrap">
                    <label class="form-label small">Amount Received (₱)</label>
                    <input type="number" name="cash_received" id="cashReceived" class="form-control form-control-sm mb-1" min="0" step="0.01">
                    <div class="d-flex justify-content-between small text-muted"><span>Change</span><span id="posChange">₱0.00</span></div>
                </div>
                <div id="refWrap" class="d-none">
                    <label class="form-label small">Reference Number <span class="text-danger">*</span></label>
                    <input type="text" name="reference_number" id="referenceNumber" class="form-control form-control-sm mb-2">
                    <label class="form-label small">Proof of Payment (optional)</label>
                    <input type="file" name="proof" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.pdf">
                </div>

                <div id="checkoutError" class="alert alert-danger py-2 small d-none mt-2"></div>

                <button type="submit" class="btn btn-primary w-100 mt-2" id="completeSaleBtn" disabled>
                    <i class="bi bi-check-circle me-1"></i>Complete Sale
                </button>
            </form>
        </div>
    </div>
</div>

<div class="ts-card p-3 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Recent Sales</h2>
        <a href="<?= BASE_URL ?>/orders/index.php" class="small text-decoration-none">View Full History →</a>
    </div>
    <?php if (!$recentSales): ?>
        <div class="empty-state py-3"><i class="bi bi-receipt"></i><p class="mb-0 small">No sales yet today.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Sale #</th><th>Time</th><th>Type</th><th>Amount</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recentSales as $s): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars($s['sale_number']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars(date('g:ia', strtotime($s['created_at']))) ?></td>
                        <td><span class="badge-status badge-neutral"><?= ucfirst($s['customer_type']) ?></span></td>
                        <td>₱<?= number_format((float) $s['total_amount'], 2) ?></td>
                        <td class="small"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($s['payment_method'] ?? '—'))) ?></td>
                        <td><span class="badge-status <?= $s['payment_status'] === 'verified' ? 'badge-success' : ($s['payment_status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>"><?= ucfirst($s['payment_status'] ?? '—') ?></span></td>
                        <td class="text-end"><a href="<?= BASE_URL ?>/pos/receipt.php?sale_id=<?= $s['sale_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt-cutoff"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
const PRODUCTS = <?= json_encode($products, JSON_HEX_TAG) ?>;
let cart = {}; // product_id -> { product, quantity }
let currentCategory = '';
let searchTerm = '';

function money(n) { return '₱' + Number(n).toFixed(2); }

function renderGrid() {
    const grid = document.getElementById('posProductGrid');
    const noResults = document.getElementById('posNoResults');
    const term = searchTerm.toLowerCase();
    const filtered = PRODUCTS.filter(p => {
        const matchesCat = !currentCategory || String(p.category_id) === String(currentCategory);
        const matchesSearch = !term || p.name.toLowerCase().includes(term) || p.sku.toLowerCase().includes(term);
        return matchesCat && matchesSearch;
    });

    grid.innerHTML = '';
    noResults.classList.toggle('d-none', filtered.length > 0);

    filtered.forEach(p => {
        const outOfStock = p.stock <= 0;
        const col = document.createElement('div');
        col.className = 'col-6 col-md-4';
        col.innerHTML = `
            <div class="ts-card p-2 h-100 d-flex flex-column justify-content-between ${outOfStock ? 'opacity-50' : ''}" style="cursor:${outOfStock ? 'not-allowed' : 'pointer'};" data-id="${p.id}">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-1">
                        <div class="fw-semibold small">${p.name}</div>
                        ${p.promo_label ? `<span class="badge-status badge-danger flex-shrink-0" style="font-size:0.6rem;">🏷 ${p.promo_label}</span>` : ''}
                    </div>
                    <div class="text-muted" style="font-size:0.72rem;">${p.sku}</div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="fw-bold small">${money(p.selling_price)}</span>
                    <span class="badge-status ${outOfStock ? 'badge-danger' : (p.stock <= 10 ? 'badge-warning' : 'badge-success')}" style="font-size:0.65rem;">${outOfStock ? 'Out of stock' : p.stock + ' left'}</span>
                </div>
            </div>`;
        if (!outOfStock) {
            col.querySelector('.ts-card').addEventListener('click', () => addToCart(p.id));
        }
        grid.appendChild(col);
    });
}

function addToCart(productId) {
    const product = PRODUCTS.find(p => p.id === productId);
    if (!product) return;
    const existing = cart[productId];
    const currentQty = existing ? existing.quantity : 0;
    if (currentQty + 1 > product.stock) {
        showCheckoutError(`Only ${product.stock} unit(s) of "${product.name}" available.`);
        return;
    }
    cart[productId] = { product, quantity: currentQty + 1 };
    renderCart();
}

function changeQty(productId, delta) {
    const entry = cart[productId];
    if (!entry) return;
    const newQty = entry.quantity + delta;
    if (newQty <= 0) { delete cart[productId]; renderCart(); return; }
    if (newQty > entry.product.stock) {
        showCheckoutError(`Only ${entry.product.stock} unit(s) of "${entry.product.name}" available.`);
        return;
    }
    entry.quantity = newQty;
    renderCart();
}

function removeFromCart(productId) {
    delete cart[productId];
    renderCart();
}

function renderCart() {
    const list = document.getElementById('posCartList');
    const empty = document.getElementById('posCartEmpty');
    const entries = Object.values(cart);

    empty.classList.toggle('d-none', entries.length > 0);
    list.classList.toggle('d-none', entries.length === 0);
    document.getElementById('completeSaleBtn').disabled = entries.length === 0;

    list.innerHTML = entries.map(e => `
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <div class="me-2">
                <div class="small fw-semibold">${e.product.name}</div>
                <div class="text-muted" style="font-size:0.72rem;">${money(e.product.selling_price)} each</div>
            </div>
            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="changeQty(${e.product.id}, -1)">−</button>
                <span class="small" style="min-width:20px;text-align:center;">${e.quantity}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="changeQty(${e.product.id}, 1)">+</button>
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="removeFromCart(${e.product.id})"><i class="bi bi-x"></i></button>
            </div>
        </div>`).join('');

    updateTotals();
}

function updateTotals() {
    const entries = Object.values(cart);
    const subtotal = entries.reduce((sum, e) => sum + (e.product.selling_price * e.quantity), 0);
    let discount = parseFloat(document.getElementById('posDiscount').value) || 0;
    discount = Math.max(0, Math.min(discount, subtotal));
    const total = subtotal - discount;

    document.getElementById('posSubtotal').textContent = money(subtotal);
    document.getElementById('posTotal').textContent = money(total);

    const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
    document.getElementById('posChange').textContent = money(Math.max(0, cashReceived - total));

    return { subtotal, discount, total };
}

function showCheckoutError(msg) {
    const el = document.getElementById('checkoutError');
    el.textContent = msg;
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 4000);
}

document.getElementById('posSearch').addEventListener('input', (e) => { searchTerm = e.target.value; renderGrid(); });
document.querySelectorAll('.category-chip').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.category-chip').forEach(b => b.classList.replace('btn-primary', 'btn-outline-secondary'));
        btn.classList.replace('btn-outline-secondary', 'btn-primary');
        currentCategory = btn.dataset.cat;
        renderGrid();
    });
});
document.getElementById('posDiscount').addEventListener('input', updateTotals);
document.getElementById('cashReceived').addEventListener('input', updateTotals);

document.querySelectorAll('input[name="customer_type"]').forEach(radio => {
    radio.addEventListener('change', () => {
        const isReseller = document.getElementById('ctypeReseller').checked;
        document.getElementById('resellerWrap').classList.toggle('d-none', !isReseller);
        document.getElementById('retailCustomerWrap').classList.toggle('d-none', isReseller);
    });
});

document.getElementById('paymentMethod').addEventListener('change', (e) => {
    const isCash = e.target.value === 'cash';
    document.getElementById('cashWrap').classList.toggle('d-none', !isCash);
    document.getElementById('refWrap').classList.toggle('d-none', isCash);
});

document.getElementById('checkoutForm').addEventListener('submit', function (e) {
    const entries = Object.values(cart);
    if (entries.length === 0) {
        e.preventDefault();
        showCheckoutError('Cart is empty.');
        return;
    }

    const { total } = updateTotals();
    const method = document.getElementById('paymentMethod').value;

    if (document.getElementById('ctypeReseller').checked && !document.getElementById('resellerSelect').value) {
        e.preventDefault();
        showCheckoutError('Please select a reseller.');
        return;
    }
    if (method === 'cash') {
        const received = parseFloat(document.getElementById('cashReceived').value) || 0;
        if (received < total) {
            e.preventDefault();
            showCheckoutError('Amount received is less than the total.');
            return;
        }
    } else {
        if (!document.getElementById('referenceNumber').value.trim()) {
            e.preventDefault();
            showCheckoutError('Reference number is required for ' + (method === 'gcash' ? 'GCash' : 'Bank Transfer') + '.');
            return;
        }
    }

    document.getElementById('cartJsonInput').value = JSON.stringify(
        entries.map(e => ({ product_id: e.product.id, quantity: e.quantity }))
    );
});

renderGrid();
renderCart();
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>

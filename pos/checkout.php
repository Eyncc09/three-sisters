<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('pos.use');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/pos/index.php');
    exit;
}

if (!csrfValidate($_POST['csrf_token'] ?? null)) {
    header('Location: ' . BASE_URL . '/pos/index.php?error=' . urlencode('Your session expired. Please try the sale again.'));
    exit;
}

$cartRaw = $_POST['cart_json'] ?? '[]';
$cart = json_decode($cartRaw, true);
if (!is_array($cart)) {
    header('Location: ' . BASE_URL . '/pos/index.php?error=' . urlencode('Invalid cart data.'));
    exit;
}

$customerType = in_array($_POST['customer_type'] ?? '', ['retail', 'reseller'], true) ? $_POST['customer_type'] : 'retail';
$customerId = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
$resellerId = !empty($_POST['reseller_id']) ? (int) $_POST['reseller_id'] : null;
// Client-side total (shown in the cart panel) is for display only — OrderService
// recomputes subtotal/discount/total authoritatively from current DB prices and
// clamps discount to [0, subtotal], so a tampered value here can't be exploited.
$discountAmount = (float) ($_POST['discount_amount'] ?? 0);

// ---------- Stage 4C: server-side promotion discount ----------
// Computed entirely from the database (current selling_price + currently-active
// promotions) — the client never sends promotion info, so there is nothing here
// to trust or distrust from JavaScript. This does not modify OrderService or its
// atomic checkout transaction at all; it only decides what discount_amount to pass
// into the SAME completeSale() call Stage 3 already had, exactly as before.
$promoAppliedDetails = [];
if ($cart) {
    $productIds = array_values(array_unique(array_filter(array_map(
        static fn($i) => (int) ($i['product_id'] ?? 0), $cart
    ), static fn($id) => $id > 0)));

    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $priceStmt = db()->prepare("SELECT id, selling_price FROM products WHERE id IN ($placeholders)");
        $priceStmt->execute($productIds);
        $priceByProduct = [];
        foreach ($priceStmt->fetchAll() as $row) {
            $priceByProduct[(int) $row['id']] = (float) $row['selling_price'];
        }

        $promoCartItems = [];
        foreach ($cart as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0 && isset($priceByProduct[$pid])) {
                $promoCartItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $priceByProduct[$pid]];
            }
        }

        $promoResult = PromotionService::computeApplicableDiscount($promoCartItems);
        // The server-computed promotion discount is a FLOOR, not a suggestion: a cashier's
        // manual discount can only add to it, never let a customer receive less than what
        // an active promotion already entitles them to.
        $discountAmount = max($discountAmount, $promoResult['discount']);
        $promoAppliedDetails = $promoResult['applied'];
    }
}

$paymentMethod = $_POST['payment_method'] ?? 'cash';
$referenceNumber = trim($_POST['reference_number'] ?? '');
$cashReceived = (float) ($_POST['cash_received'] ?? 0);

try {
    $proof = UploadHelper::handlePaymentProof($_FILES['proof'] ?? null);
} catch (RuntimeException $e) {
    header('Location: ' . BASE_URL . '/pos/index.php?error=' . urlencode($e->getMessage()));
    exit;
}

try {
    $result = OrderService::completeSale(
        $cart,
        $customerType,
        $customerId,
        $resellerId,
        $discountAmount,
        [
            'method' => $paymentMethod,
            'reference_number' => $referenceNumber ?: null,
            'cash_received' => $cashReceived,
            'proof' => $proof,
        ],
        (int) $_SESSION['user_id']
    );
} catch (RuntimeException|InvalidArgumentException $e) {
    header('Location: ' . BASE_URL . '/pos/index.php?error=' . urlencode($e->getMessage()));
    exit;
} catch (Throwable $e) {
    error_log('[POS CHECKOUT ERROR] ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/pos/index.php?error=' . urlencode('A system error occurred while processing the sale. No charge was made — please try again.'));
    exit;
}

if ($promoAppliedDetails) {
    AuditLogger::log((int) $_SESSION['user_id'], 'promotion.applied', 'promotions', 'orders', $result['order_id'], [
        'sale_number' => $result['sale_number'], 'promotions' => $promoAppliedDetails,
    ]);
}

header('Location: ' . BASE_URL . '/pos/receipt.php?sale_id=' . $result['sale_id'] . '&fresh=1');
exit;

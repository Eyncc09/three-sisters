<?php
declare(strict_types=1);

/**
 * OrderService — central hub per the Phase 0 architecture. A POS checkout
 * creates ONE `orders` row + `order_items`, extended by a `sales` row
 * (POS-specific: cashier, customer_type) and a `payments` row.
 *
 * Design note carried over from schema.sql: there is intentionally no
 * separate `sale_items` table — `order_items` (via `sales.order_id`) is
 * the single source of truth for line items, so POS/reseller/TikTok
 * transactions never duplicate item data across tables.
 *
 * completeSale() is the ONLY way a POS transaction is created. It wraps
 * everything — stock validation, order, items, sale, payment, and every
 * inventory deduction — in one DB transaction via InventoryService's
 * nesting-safe recordMovement(). Any failure rolls back the whole thing;
 * nothing partially commits.
 */
final class OrderService
{
    public const PER_PAGE = 15;

    /**
     * @param array $cartItems  [['product_id' => int, 'quantity' => int], ...]
     * @param array $payment    ['method' => 'cash'|'gcash'|'bank_transfer', 'reference_number' => ?string,
     *                           'cash_received' => ?float, 'proof' => ?['path','mime','size']]
     * @return array Result summary used to render the receipt.
     * @throws RuntimeException on validation/stock failure (safe to show message to user)
     */
    public static function completeSale(
        array $cartItems,
        string $customerType,
        ?int $customerId,
        ?int $resellerId,
        float $discountAmount,
        array $payment,
        int $cashierId
    ): array {
        if (!in_array($customerType, ['retail', 'reseller'], true)) {
            throw new InvalidArgumentException('Invalid customer type.');
        }
        if ($customerType === 'reseller' && !$resellerId) {
            throw new RuntimeException('Please select a reseller for a reseller transaction.');
        }
        $method = $payment['method'] ?? '';
        if (!in_array($method, ['cash', 'gcash', 'bank_transfer'], true)) {
            throw new InvalidArgumentException('Invalid payment method.');
        }
        if (!$cartItems) {
            throw new RuntimeException('The cart is empty.');
        }

        // Merge duplicate product_id entries (defensive — client shouldn't send dupes, but don't trust it).
        $merged = [];
        foreach ($cartItems as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $merged[$pid] = ($merged[$pid] ?? 0) + $qty;
        }
        if (!$merged) {
            throw new RuntimeException('The cart has no valid items.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $subtotal = 0.0;
            $lineItems = [];
            $insufficient = [];

            foreach ($merged as $productId => $qty) {
                $stmt = $pdo->prepare(
                    'SELECT p.id, p.name, p.sku, p.selling_price, p.status, i.quantity_on_hand
                     FROM products p JOIN inventory i ON i.product_id = p.id
                     WHERE p.id = :id FOR UPDATE'
                );
                $stmt->execute(['id' => $productId]);
                $row = $stmt->fetch();

                if (!$row || $row['status'] !== 'active') {
                    throw new RuntimeException('One of the items in the cart is no longer available. Please refresh and try again.');
                }
                if ((int) $row['quantity_on_hand'] < $qty) {
                    $insufficient[] = sprintf('%s (available: %d, requested: %d)', $row['name'], (int) $row['quantity_on_hand'], $qty);
                    continue;
                }

                $unitPrice = (float) $row['selling_price'];
                $lineTotal = round($unitPrice * $qty, 2);
                $subtotal += $lineTotal;
                $lineItems[] = [
                    'product_id' => (int) $productId, 'sku' => $row['sku'], 'name' => $row['name'],
                    'quantity' => $qty, 'unit_price' => $unitPrice, 'line_total' => $lineTotal,
                ];
            }

            if ($insufficient) {
                throw new RuntimeException('Insufficient stock for: ' . implode('; ', $insufficient));
            }

            $discountAmount = max(0.0, min($discountAmount, $subtotal));
            $total = round($subtotal - $discountAmount, 2);

            if ($method === 'cash') {
                $cashReceived = (float) ($payment['cash_received'] ?? 0);
                if ($cashReceived < $total) {
                    throw new RuntimeException('Cash received is less than the total amount due.');
                }
            } else {
                $refNumber = trim((string) ($payment['reference_number'] ?? ''));
                if ($refNumber === '') {
                    throw new RuntimeException('A reference number is required for ' . ($method === 'gcash' ? 'GCash' : 'Bank Transfer') . ' payments.');
                }
            }

            // --- orders ---
            $channel = $customerType === 'reseller' ? 'reseller' : 'physical';
            $tempOrderNum = 'TMP-' . bin2hex(random_bytes(8));
            $pdo->prepare(
                'INSERT INTO orders (order_number, channel, customer_id, reseller_id, status, subtotal, discount_amount, total_amount, placed_by)
                 VALUES (:num, :channel, :cid, :rid, "completed", :subtotal, :discount, :total, :placed_by)'
            )->execute([
                'num' => $tempOrderNum, 'channel' => $channel,
                'cid' => $customerType === 'retail' ? ($customerId ?: null) : null,
                'rid' => $customerType === 'reseller' ? $resellerId : null,
                'subtotal' => $subtotal, 'discount' => $discountAmount, 'total' => $total, 'placed_by' => $cashierId,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $orderNumber = sprintf('ORD-%d-%06d', (int) date('Y'), $orderId);
            $pdo->prepare('UPDATE orders SET order_number = :num WHERE id = :id')->execute(['num' => $orderNumber, 'id' => $orderId]);

            // --- order_items (single source of truth for line items) ---
            $itemStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price, discount, line_total)
                 VALUES (:oid, :pid, :qty, :price, 0, :line_total)'
            );
            foreach ($lineItems as $li) {
                $itemStmt->execute([
                    'oid' => $orderId, 'pid' => $li['product_id'], 'qty' => $li['quantity'],
                    'price' => $li['unit_price'], 'line_total' => $li['line_total'],
                ]);
            }

            // --- sales (POS-specific extension of the order) ---
            $tempSaleNum = 'TMP-' . bin2hex(random_bytes(8));
            $pdo->prepare(
                'INSERT INTO sales (order_id, sale_number, cashier_id, customer_type, status) VALUES (:oid, :num, :cashier, :ctype, "completed")'
            )->execute(['oid' => $orderId, 'num' => $tempSaleNum, 'cashier' => $cashierId, 'ctype' => $customerType]);
            $saleId = (int) $pdo->lastInsertId();
            $saleNumber = sprintf('SALE-%d-%06d', (int) date('Y'), $saleId);
            $pdo->prepare('UPDATE sales SET sale_number = :num WHERE id = :id')->execute(['num' => $saleNumber, 'id' => $saleId]);

            // --- payment ---
            $paymentStatus = $method === 'cash' ? 'verified' : 'pending';
            $pdo->prepare(
                'INSERT INTO payments (order_id, payment_method, amount, reference_number, status, verified_by, verified_at)
                 VALUES (:oid, :method, :amount, :ref, :status, :vby, :vat)'
            )->execute([
                'oid' => $orderId, 'method' => $method, 'amount' => $total,
                'ref' => $payment['reference_number'] ?? null,
                'status' => $paymentStatus,
                'vby' => $paymentStatus === 'verified' ? $cashierId : null,
                'vat' => $paymentStatus === 'verified' ? date('Y-m-d H:i:s') : null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            if (!empty($payment['proof'])) {
                $pdo->prepare(
                    'INSERT INTO payment_proofs (payment_id, file_path, mime_type, file_size) VALUES (:pid, :path, :mime, :size)'
                )->execute([
                    'pid' => $paymentId,
                    'path' => $payment['proof']['path'],
                    'mime' => $payment['proof']['mime'],
                    'size' => $payment['proof']['size'],
                ]);
            }

            // --- inventory deduction (nested — uses this same open transaction) ---
            $movementType = $customerType === 'reseller' ? 'reseller_order' : 'sale';
            foreach ($lineItems as $li) {
                InventoryService::recordMovement(
                    $li['product_id'], $movementType, -$li['quantity'],
                    'POS sale ' . $saleNumber, $cashierId, 'order', $orderId
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Side effects deferred until after the commit succeeds (see InventoryService::recordMovement docblock).
        foreach ($lineItems as $li) {
            AuditLogger::log($cashierId, 'inventory.movement.' . $movementType, 'inventory', 'products', $li['product_id'], [
                'quantity' => -$li['quantity'], 'reason' => 'POS sale ' . $saleNumber, 'order_id' => $orderId,
            ]);
            InventoryService::refreshLowStockAlert($li['product_id']);
        }

        AuditLogger::log($cashierId, 'sale.completed', 'pos', 'orders', $orderId, [
            'sale_number' => $saleNumber, 'total' => $total, 'customer_type' => $customerType, 'items' => count($lineItems),
        ]);
        AuditLogger::log($cashierId, 'payment.recorded', 'payments', 'payments', $paymentId, [
            'method' => $method, 'status' => $paymentStatus, 'amount' => $total,
        ]);

        return [
            'order_id' => $orderId, 'sale_id' => $saleId,
            'order_number' => $orderNumber, 'sale_number' => $saleNumber,
            'subtotal' => $subtotal, 'discount_amount' => $discountAmount, 'total' => $total,
            'cash_received' => $method === 'cash' ? (float) ($payment['cash_received'] ?? 0) : null,
            'change' => $method === 'cash' ? round(((float) ($payment['cash_received'] ?? 0)) - $total, 2) : null,
        ];
    }

    /** Full receipt/detail payload for a completed sale. */
    public static function getReceiptData(int $saleId): ?array
    {
        $stmt = db()->prepare(
            "SELECT s.*, o.order_number, o.channel, o.subtotal, o.discount_amount, o.total_amount, o.created_at AS order_created_at,
                    u.full_name AS cashier_name,
                    c.full_name AS customer_name, c.phone AS customer_phone,
                    r.full_name AS reseller_name, r.business_name AS reseller_business,
                    p.payment_method, p.reference_number, p.status AS payment_status, p.amount AS payment_amount
             FROM sales s
             JOIN orders o ON o.id = s.order_id
             JOIN users u ON u.id = s.cashier_id
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN resellers r ON r.id = o.reseller_id
             LEFT JOIN payments p ON p.order_id = o.id
             WHERE s.id = :sid"
        );
        $stmt->execute(['sid' => $saleId]);
        $sale = $stmt->fetch();
        if (!$sale) return null;

        $itemStmt = db()->prepare(
            'SELECT oi.*, p.name, p.sku FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :oid'
        );
        $itemStmt->execute(['oid' => $sale['order_id']]);
        $sale['items'] = $itemStmt->fetchAll();

        return $sale;
    }

    /** @return array{items: array, total: int, page: int, pages: int} */
    public static function transactionHistory(array $filters, int $page = 1, ?int $restrictToCashierId = null): array
    {
        $where = ['1=1'];
        $params = [];

        if ($restrictToCashierId !== null) {
            $where[] = 's.cashier_id = :cashier_id';
            $params['cashier_id'] = $restrictToCashierId;
        }
        if (!empty($filters['q'])) {
            $where[] = '(o.order_number LIKE :q OR s.sale_number LIKE :q OR c.full_name LIKE :q OR r.full_name LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['customer_type'])) {
            $where[] = 's.customer_type = :ctype';
            $params['ctype'] = $filters['customer_type'];
        }
        if (!empty($filters['payment_method'])) {
            $where[] = 'p.payment_method = :pmethod';
            $params['pmethod'] = $filters['payment_method'];
        }
        if (!empty($filters['payment_status'])) {
            $where[] = 'p.status = :pstatus';
            $params['pstatus'] = $filters['payment_status'];
        }
        if (!empty($filters['order_status'])) {
            $where[] = 'o.status = :ostatus';
            $params['ostatus'] = $filters['order_status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(s.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(s.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $baseFrom = 'FROM sales s
             JOIN orders o ON o.id = s.order_id
             JOIN users u ON u.id = s.cashier_id
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN resellers r ON r.id = o.reseller_id
             LEFT JOIN payments p ON p.order_id = o.id';

        $countStmt = db()->prepare("SELECT COUNT(*) $baseFrom WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT s.id AS sale_id, s.sale_number, s.customer_type, s.created_at,
                       o.id AS order_id, o.order_number, o.channel, o.status AS order_status, o.total_amount,
                       u.full_name AS cashier_name,
                       c.full_name AS customer_name, r.full_name AS reseller_name,
                       p.payment_method, p.status AS payment_status
                $baseFrom
                WHERE $whereSql
                ORDER BY s.created_at DESC
                LIMIT " . self::PER_PAGE . " OFFSET $offset";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    public static function recentSales(?int $cashierId = null, int $limit = 8): array
    {
        $where = $cashierId !== null ? 'WHERE s.cashier_id = :cashier_id' : '';
        $sql = "SELECT s.id AS sale_id, s.sale_number, s.customer_type, s.created_at,
                       o.total_amount, p.payment_method, p.status AS payment_status
                FROM sales s
                JOIN orders o ON o.id = s.order_id
                LEFT JOIN payments p ON p.order_id = o.id
                $where
                ORDER BY s.created_at DESC LIMIT " . (int) $limit;
        $stmt = db()->prepare($sql);
        if ($cashierId !== null) {
            $stmt->execute(['cashier_id' => $cashierId]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    public static function findOrderDetail(int $orderId): ?array
    {
        $stmt = db()->prepare(
            "SELECT o.*, c.full_name AS customer_name, c.phone AS customer_phone,
                    r.full_name AS reseller_name, r.business_name AS reseller_business,
                    u.full_name AS placed_by_name,
                    s.id AS sale_id, s.sale_number, s.cashier_id, s.customer_type,
                    cu.full_name AS cashier_name
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN resellers r ON r.id = o.reseller_id
             LEFT JOIN users u ON u.id = o.placed_by
             LEFT JOIN sales s ON s.order_id = o.id
             LEFT JOIN users cu ON cu.id = s.cashier_id
             WHERE o.id = :id"
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) return null;

        $itemStmt = db()->prepare('SELECT oi.*, p.name, p.sku FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :oid');
        $itemStmt->execute(['oid' => $orderId]);
        $order['items'] = $itemStmt->fetchAll();

        $payStmt = db()->prepare('SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC');
        $payStmt->execute(['oid' => $orderId]);
        $order['payments'] = $payStmt->fetchAll();

        return $order;
    }

    public static function updateStatus(int $orderId, string $newStatus, int $userId): void
    {
        $allowed = ['pending', 'confirmed', 'preparing', 'ready', 'shipped', 'completed', 'cancelled', 'returned'];
        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException('Invalid order status.');
        }
        db()->prepare('UPDATE orders SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $orderId]);
        AuditLogger::log($userId, 'order.status_changed', 'orders', 'orders', $orderId, ['new_status' => $newStatus]);
    }
}

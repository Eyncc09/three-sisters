<?php
declare(strict_types=1);

/**
 * InventoryService — the single path through which stock quantities change.
 * `inventory.quantity_on_hand` is only ever written here, inside the same
 * DB transaction as the corresponding `stock_movements` row, so the two
 * can never drift apart (spec section 10/13).
 */
final class InventoryService
{
    /** Movement types allowed by the schema's ENUM. */
    public const TYPES = ['stock_in', 'sale', 'reseller_order', 'tiktok_order', 'return', 'damaged', 'expired', 'adjustment'];

    public static function ensureRow(int $productId): void
    {
        $stmt = db()->prepare('INSERT IGNORE INTO inventory (product_id, quantity_on_hand) VALUES (:pid, 0)');
        $stmt->execute(['pid' => $productId]);
    }

    public static function currentStock(int $productId): int
    {
        $stmt = db()->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = :pid');
        $stmt->execute(['pid' => $productId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : 0;
    }

    /**
     * Records a stock movement and updates the running total atomically.
     * $quantity is SIGNED — positive increases stock, negative decreases it.
     *
     * Transaction-nesting behavior (needed for atomic POS checkout, Stage 3):
     * - If no transaction is currently active on the shared PDO connection,
     *   this method opens, commits, and rolls back its OWN transaction, exactly
     *   as before — safe to call standalone (Stage 2 stock-in/adjust pages do this).
     * - If a transaction is ALREADY active (e.g. OrderService::completeSale()
     *   started one), this method does NOT call beginTransaction()/commit() —
     *   it just performs its writes on the caller's connection/transaction and
     *   lets the caller decide the final commit/rollback. This is what makes
     *   "one order + N inventory deductions" atomic: if any later item in the
     *   cart fails, the caller's rollback undoes these writes too.
     * - Audit logging and low-stock alert refresh read/depend on the write
     *   actually being durable, so they only run here when THIS call owned
     *   the transaction. When nested, the caller (OrderService) is
     *   responsible for calling AuditLogger::log(...) and
     *   InventoryService::refreshLowStockAlert() itself, AFTER its own
     *   commit succeeds — never before, or a later rollback would leave a
     *   dangling audit entry / alert for a movement that never happened.
     */
    public static function recordMovement(
        int $productId,
        string $type,
        int $quantity,
        ?string $reason,
        int $userId,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): void {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid stock movement type.');
        }

        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            self::ensureRow($productId);

            // Lock the inventory row to prevent race conditions from concurrent sales.
            $stmt = $pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = :pid FOR UPDATE');
            $stmt->execute(['pid' => $productId]);
            $current = (int) $stmt->fetchColumn();
            $newQty = $current + $quantity;

            if ($newQty < 0) {
                throw new RuntimeException('Stock movement would result in negative inventory.');
            }

            $pdo->prepare('UPDATE inventory SET quantity_on_hand = :qty WHERE product_id = :pid')
                ->execute(['qty' => $newQty, 'pid' => $productId]);

            $pdo->prepare(
                'INSERT INTO stock_movements (product_id, movement_type, quantity, reason, reference_type, reference_id, performed_by)
                 VALUES (:pid, :type, :qty, :reason, :ref_type, :ref_id, :uid)'
            )->execute([
                'pid' => $productId, 'type' => $type, 'qty' => $quantity, 'reason' => $reason,
                'ref_type' => $referenceType, 'ref_id' => $referenceId, 'uid' => $userId,
            ]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            // Nested call: do NOT roll back here — that would abort the
            // caller's whole transaction from inside a helper. Just propagate
            // the exception; the caller's try/catch owns the rollback.
            throw $e;
        }

        if ($ownsTransaction) {
            AuditLogger::log($userId, 'inventory.movement.' . $type, 'inventory', 'products', $productId, [
                'quantity' => $quantity, 'reason' => $reason,
            ]);
            self::refreshLowStockAlert($productId);
        }
        // else: caller is inside its own transaction and must call
        // AuditLogger::log(...) + self::refreshLowStockAlert($productId)
        // itself, after its own commit() succeeds.
    }

    public static function stockIn(int $productId, int $quantity, string $reason, int $userId): void
    {
        self::recordMovement($productId, 'stock_in', abs($quantity), $reason, $userId);
    }

    /** Manual adjustment — quantity may be positive or negative. */
    public static function adjust(int $productId, int $signedQuantity, string $reason, int $userId): void
    {
        self::recordMovement($productId, 'adjustment', $signedQuantity, $reason, $userId);
    }

    /**
     * Stock status against reorder level — transparent, defense-friendly rule:
     *   0 units                       -> critical
     *   <= 50% of reorder level       -> critical
     *   <= reorder level              -> low
     *   otherwise                     -> safe
     */
    public static function stockStatus(int $quantityOnHand, int $reorderLevel): string
    {
        if ($quantityOnHand <= 0) return 'critical';
        if ($reorderLevel > 0 && $quantityOnHand <= $reorderLevel * 0.5) return 'critical';
        if ($quantityOnHand <= $reorderLevel) return 'low';
        return 'safe';
    }

    /** Expiration status against a configurable alert window (default 30 days). */
    public static function expirationStatus(?string $expirationDate, int $windowDays = 30): ?string
    {
        if (!$expirationDate) return null;
        $today = new DateTimeImmutable('today');
        $expiry = new DateTimeImmutable($expirationDate);
        $daysLeft = (int) $today->diff($expiry)->format('%r%a');

        if ($daysLeft < 0) return 'expired';
        if ($daysLeft <= $windowDays) return 'expiring_soon';
        return 'safe';
    }

    /**
     * Recomputes the active low_stock/critical_stock alert for a product —
     * inserts a new one, updates the message, or resolves it if stock recovered.
     */
    public static function refreshLowStockAlert(int $productId): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT p.name, p.sku, p.reorder_level, i.quantity_on_hand
             FROM products p JOIN inventory i ON i.product_id = p.id
             WHERE p.id = :pid'
        );
        $stmt->execute(['pid' => $productId]);
        $row = $stmt->fetch();
        if (!$row) return;

        $status = self::stockStatus((int) $row['quantity_on_hand'], (int) $row['reorder_level']);

        $resolveStmt = $pdo->prepare(
            "UPDATE stock_alerts SET status = 'resolved', resolved_at = NOW()
             WHERE product_id = :pid AND alert_type IN ('low_stock','critical_stock') AND status = 'active'"
        );

        if ($status === 'safe') {
            $resolveStmt->execute(['pid' => $productId]);
            return;
        }

        $alertType = $status === 'critical' ? 'critical_stock' : 'low_stock';
        $message = sprintf(
            '%s (%s) is at %s stock: %d of %d reorder level.',
            $row['name'], $row['sku'], $status, (int) $row['quantity_on_hand'], (int) $row['reorder_level']
        );

        // Resolve any stale alert of the *other* stock type, then upsert the current one.
        $pdo->prepare(
            "UPDATE stock_alerts SET status = 'resolved', resolved_at = NOW()
             WHERE product_id = :pid AND alert_type IN ('low_stock','critical_stock') AND status = 'active' AND alert_type != :type"
        )->execute(['pid' => $productId, 'type' => $alertType]);

        $existing = $pdo->prepare(
            "SELECT id FROM stock_alerts WHERE product_id = :pid AND alert_type = :type AND status = 'active'"
        );
        $existing->execute(['pid' => $productId, 'type' => $alertType]);

        if ($id = $existing->fetchColumn()) {
            $pdo->prepare('UPDATE stock_alerts SET message = :msg WHERE id = :id')
                ->execute(['msg' => $message, 'id' => $id]);
        } else {
            $pdo->prepare(
                "INSERT INTO stock_alerts (product_id, alert_type, message, status) VALUES (:pid, :type, :msg, 'active')"
            )->execute(['pid' => $productId, 'type' => $alertType, 'msg' => $message]);
        }
    }

    /** Recent stock movements, optionally filtered by product. */
    public static function recentMovements(?int $productId = null, int $limit = 50): array
    {
        $sql = "SELECT sm.*, p.name AS product_name, p.sku, u.full_name AS performed_by_name
                FROM stock_movements sm
                JOIN products p ON p.id = sm.product_id
                LEFT JOIN users u ON u.id = sm.performed_by";
        $params = [];
        if ($productId !== null) {
            $sql .= ' WHERE sm.product_id = :pid';
            $params['pid'] = $productId;
        }
        $sql .= ' ORDER BY sm.created_at DESC LIMIT ' . (int) $limit;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

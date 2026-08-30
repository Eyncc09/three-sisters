<?php
declare(strict_types=1);

/**
 * PurchaseOrderService — Stage 5.
 * Reuses existing `purchase_orders`, `purchase_order_items`, and
 * `delivery_updates` tables exactly as designed in Phase 1 — no schema
 * changes. `delivery_updates` (status + free-text note, tied to a PO) is
 * the mechanism for both status transitions AND general schedule/
 * availability communication about that specific order, per the
 * Stage 5 spec's own framing (those topics flow through communication,
 * not a new structured column).
 */
final class PurchaseOrderService
{
    public const STATUSES = ['requested', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled'];

    public static function create(int $distributorId, array $items, ?string $expectedDeliveryDate, int $userId): int
    {
        if ($expectedDeliveryDate !== null) {
            $d = DateTime::createFromFormat('Y-m-d', $expectedDeliveryDate);
            if (!$d || $d->format('Y-m-d') !== $expectedDeliveryDate) {
                throw new InvalidArgumentException('Enter a valid expected delivery date.');
            }
        }

        $items = array_values(array_filter($items, fn($i) => (int) ($i['quantity'] ?? 0) > 0 && (int) ($i['product_id'] ?? 0) > 0));
        if (!$items) {
            throw new RuntimeException('Add at least one product with a quantity.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $totalCost = 0.0;
            foreach ($items as $item) {
                $totalCost += (float) $item['unit_cost'] * (int) $item['quantity'];
            }

            $tempNumber = 'TMP-' . bin2hex(random_bytes(6));
            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders (po_number, distributor_id, status, expected_delivery_date, total_cost, created_by)
                 VALUES (:num, :did, "requested", :expected, :total, :uid)'
            );
            $stmt->execute([
                'num' => $tempNumber, 'did' => $distributorId,
                'expected' => $expectedDeliveryDate ?: null, 'total' => $totalCost, 'uid' => $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $poNumber = sprintf('PO-%d-%05d', (int) date('Y'), $id);
            $pdo->prepare('UPDATE purchase_orders SET po_number = :num WHERE id = :id')->execute(['num' => $poNumber, 'id' => $id]);

            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost) VALUES (:pid, :prod, :qty, :cost)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    'pid' => $id, 'prod' => (int) $item['product_id'],
                    'qty' => (int) $item['quantity'], 'cost' => (float) $item['unit_cost'],
                ]);
            }

            $pdo->prepare(
                "INSERT INTO delivery_updates (purchase_order_id, status, note, updated_by) VALUES (:pid, 'requested', 'Purchase order created.', :uid)"
            )->execute(['pid' => $id, 'uid' => $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($userId, 'purchase_order.created', 'distributors', 'purchase_orders', $id, [
            'po_number' => $poNumber, 'distributor_id' => $distributorId, 'total_cost' => $totalCost, 'item_count' => count($items),
        ]);

        return $id;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT po.*, d.name AS distributor_name, d.lead_time_days, u.full_name AS created_by_name
             FROM purchase_orders po
             JOIN distributors d ON d.id = po.distributor_id
             LEFT JOIN users u ON u.id = po.created_by
             WHERE po.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $po = $stmt->fetch();
        if (!$po) return null;

        $itemStmt = db()->prepare(
            'SELECT poi.*, p.sku, p.name FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE poi.purchase_order_id = :id'
        );
        $itemStmt->execute(['id' => $id]);
        $po['items'] = $itemStmt->fetchAll();

        $updateStmt = db()->prepare(
            'SELECT du.*, u.full_name AS updated_by_name FROM delivery_updates du
             LEFT JOIN users u ON u.id = du.updated_by
             WHERE du.purchase_order_id = :id ORDER BY du.created_at DESC'
        );
        $updateStmt->execute(['id' => $id]);
        $po['updates'] = $updateStmt->fetchAll();

        return $po;
    }

    public static function all(array $filters = [], ?int $distributorId = null): array
    {
        $where = ['1=1'];
        $params = [];
        if ($distributorId !== null) {
            $where[] = 'po.distributor_id = :did';
            $params['did'] = $distributorId;
        }
        if (!empty($filters['status'])) {
            $where[] = 'po.status = :status';
            $params['status'] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        $stmt = db()->prepare(
            "SELECT po.*, d.name AS distributor_name, COUNT(poi.id) AS item_count
             FROM purchase_orders po
             JOIN distributors d ON d.id = po.distributor_id
             LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
             WHERE $whereSql
             GROUP BY po.id
             ORDER BY po.created_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Purchase orders expected to arrive soon, not yet delivered/cancelled — for dashboard widgets. */
    public static function upcomingDeliveries(int $withinDays = 14): array
    {
        $stmt = db()->prepare(
            "SELECT po.*, d.name AS distributor_name
             FROM purchase_orders po JOIN distributors d ON d.id = po.distributor_id
             WHERE po.status NOT IN ('delivered', 'cancelled')
               AND po.expected_delivery_date IS NOT NULL
               AND po.expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
             ORDER BY po.expected_delivery_date ASC"
        );
        $stmt->execute(['days' => $withinDays]);
        return $stmt->fetchAll();
    }

    /**
     * Status transition — used by both Owner and Distributor (the calling
     * page enforces WHO may call this and on WHICH purchase order; this
     * method only enforces that the status value itself is valid).
     */
    public static function updateStatus(int $id, string $newStatus, int $userId, ?string $note = null): void
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid purchase order status.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
            $pdo->prepare('INSERT INTO delivery_updates (purchase_order_id, status, note, updated_by) VALUES (:pid, :status, :note, :uid)')
                ->execute(['pid' => $id, 'status' => $newStatus, 'note' => $note ?: null, 'uid' => $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($userId, 'purchase_order.status_changed', 'distributors', 'purchase_orders', $id, ['new_status' => $newStatus]);
    }

    /** Distributor (or Owner) updates the expected delivery date / posts a schedule note without changing status. */
    public static function postScheduleUpdate(int $id, ?string $expectedDeliveryDate, string $note, int $userId): void
    {
        if ($expectedDeliveryDate !== null) {
            $d = DateTime::createFromFormat('Y-m-d', $expectedDeliveryDate);
            if (!$d || $d->format('Y-m-d') !== $expectedDeliveryDate) {
                throw new InvalidArgumentException('Enter a valid expected delivery date.');
            }
        }
        if (trim($note) === '') {
            throw new InvalidArgumentException('A note is required for a schedule update.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($expectedDeliveryDate) {
                $pdo->prepare('UPDATE purchase_orders SET expected_delivery_date = :date WHERE id = :id')
                    ->execute(['date' => $expectedDeliveryDate, 'id' => $id]);
            }
            $pdo->prepare("INSERT INTO delivery_updates (purchase_order_id, status, note, updated_by) VALUES (:pid, 'schedule_update', :note, :uid)")
                ->execute(['pid' => $id, 'note' => $note, 'uid' => $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($userId, 'purchase_order.schedule_updated', 'distributors', 'purchase_orders', $id, [
            'expected_delivery_date' => $expectedDeliveryDate, 'note' => $note,
        ]);
    }
}

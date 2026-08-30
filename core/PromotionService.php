<?php
declare(strict_types=1);

/**
 * PromotionService — Stage 4C.
 *
 * Uses ONLY the existing `promotions`, `promotion_items`, and
 * `promo_performance` tables (Phase 1 schema). No new tables, no enum
 * changes. `promotions.status` keeps its original four values:
 * scheduled | active | inactive | ended.
 *
 * Status handling, explained (important for defense):
 * The stored `status` column is a best-effort snapshot written at the
 * moment the Owner creates/edits/activates/deactivates a promotion — it
 * is NOT re-computed by any background job (this project has no cron).
 * So a promotion saved as "scheduled" will still literally say
 * "scheduled" in the database after its start date arrives, until
 * someone touches it again. To stay correct without a scheduler:
 *
 * - `effectiveStatus()` derives the DISPLAY status fresh from today's
 *   date every time a promotion is shown, rather than trusting the
 *   stored value for scheduled/active/ended. The one value that IS
 *   trusted literally is 'inactive' — that's the Owner's explicit
 *   on/off switch and only changes when they flip it.
 * - `isCurrentlyActive()` (used by POS discount lookup) applies the same
 *   logic: not inactive, and today is within [start_date, end_date].
 *   This is deliberately independent of whatever effectiveStatus()
 *   would currently render, so checkout correctness never depends on
 *   anyone having recently viewed/edited the promotion.
 * - The stored column is still updated on every write (create/update/
 *   activate/deactivate) so the existing `idx_promotions_status` index
 *   and simple `WHERE status = 'active'` queries stay reasonably useful,
 *   but nothing in this service trusts that column alone for a
 *   correctness-critical decision.
 */
final class PromotionService
{
    private const DISCOUNT_TYPES = ['percentage', 'fixed'];

    // ---------- Status ----------

    /** Freshly-derived display status — see class docblock. */
    public static function effectiveStatus(array $promo): string
    {
        if ($promo['status'] === 'inactive') {
            return 'inactive';
        }
        $today = date('Y-m-d');
        if ($today < $promo['start_date']) return 'scheduled';
        if ($today > $promo['end_date']) return 'ended';
        return 'active';
    }

    /** Correctness-critical check used by POS — see class docblock. */
    public static function isCurrentlyActive(array $promo): bool
    {
        if ($promo['status'] === 'inactive') {
            return false;
        }
        $today = date('Y-m-d');
        return $today >= $promo['start_date'] && $today <= $promo['end_date'];
    }

    // ---------- Validation ----------

    /**
     * @param array $input Raw $_POST
     * @param array $productIds Selected product IDs (already cast to int)
     * @return array{data: array, errors: array<string,string>}
     */
    public static function validate(array $input, array $productIds, bool $activateNow): array
    {
        $errors = [];
        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'discount_type' => in_array($input['discount_type'] ?? '', self::DISCOUNT_TYPES, true) ? $input['discount_type'] : '',
            'discount_value' => $input['discount_value'] ?? '',
            'start_date' => trim((string) ($input['start_date'] ?? '')),
            'end_date' => trim((string) ($input['end_date'] ?? '')),
        ];

        if ($data['name'] === '') {
            $errors['name'] = 'Promotion name is required.';
        }
        if ($data['discount_type'] === '') {
            $errors['discount_type'] = 'Select a discount type.';
        }
        if ($data['discount_value'] === '' || !is_numeric($data['discount_value']) || (float) $data['discount_value'] <= 0) {
            $errors['discount_value'] = 'Enter a discount value greater than zero.';
        } elseif ($data['discount_type'] === 'percentage' && (float) $data['discount_value'] > 100) {
            $errors['discount_value'] = 'Percentage discount cannot exceed 100%.';
        }

        $startDt = DateTime::createFromFormat('Y-m-d', $data['start_date']);
        $endDt = DateTime::createFromFormat('Y-m-d', $data['end_date']);
        if (!$startDt || $startDt->format('Y-m-d') !== $data['start_date']) {
            $errors['start_date'] = 'Enter a valid start date.';
        }
        if (!$endDt || $endDt->format('Y-m-d') !== $data['end_date']) {
            $errors['end_date'] = 'Enter a valid end date.';
        }
        if (!isset($errors['start_date']) && !isset($errors['end_date']) && $data['start_date'] > $data['end_date']) {
            $errors['end_date'] = 'End date must be on or after the start date.';
        }

        if (!$productIds) {
            $errors['products'] = 'Select at least one product for this promotion.';
        }

        // Spec: don't allow newly ACTIVATING a promotion that includes an already-expired
        // product without a clear warning. We treat this as a hard validation error only
        // when the Owner is turning the promotion on right now — a Draft-style inactive
        // promotion may still hold an expired product (e.g. while reworking it).
        if ($activateNow && $productIds) {
            $expired = self::expiredProductNames($productIds);
            if ($expired) {
                $errors['products'] = 'Cannot activate — these selected products are expired: ' . implode(', ', $expired) . '. Remove them or keep the promotion inactive.';
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    private static function expiredProductNames(array $productIds): array
    {
        if (!$productIds) return [];
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = db()->prepare("SELECT name, expiration_date FROM products WHERE id IN ($placeholders)");
        $stmt->execute($productIds);
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            if (InventoryService::expirationStatus($row['expiration_date']) === 'expired') {
                $names[] = $row['name'];
            }
        }
        return $names;
    }

    // ---------- CRUD ----------

    public static function all(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = 'pr.name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        $whereSql = implode(' AND ', $where);

        $stmt = db()->prepare(
            "SELECT pr.*, COUNT(pi.id) AS product_count, u.full_name AS created_by_name
             FROM promotions pr
             LEFT JOIN promotion_items pi ON pi.promotion_id = pr.id
             LEFT JOIN users u ON u.id = pr.created_by
             WHERE $whereSql
             GROUP BY pr.id
             ORDER BY pr.start_date DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['effective_status'] = self::effectiveStatus($r);
        }
        unset($r);

        if (!empty($filters['status'])) {
            $rows = array_values(array_filter($rows, fn($r) => $r['effective_status'] === $filters['status']));
        }

        return $rows;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT pr.*, u.full_name AS created_by_name FROM promotions pr LEFT JOIN users u ON u.id = pr.created_by WHERE pr.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $promo = $stmt->fetch();
        if (!$promo) return null;

        $promo['effective_status'] = self::effectiveStatus($promo);
        $promo['products'] = self::productsForPromotion($id);

        return $promo;
    }

    /** Products in a promotion, with live stock/expiration status for display and warnings. */
    public static function productsForPromotion(int $promotionId): array
    {
        $stmt = db()->prepare(
            "SELECT p.id, p.sku, p.name, p.selling_price, p.reorder_level, p.expiration_date,
                    c.name AS category_name, COALESCE(i.quantity_on_hand, 0) AS current_stock
             FROM promotion_items pi
             JOIN products p ON p.id = pi.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN inventory i ON i.product_id = p.id
             WHERE pi.promotion_id = :pid
             ORDER BY p.name ASC"
        );
        $stmt->execute(['pid' => $promotionId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['stock_status'] = InventoryService::stockStatus((int) $r['current_stock'], (int) $r['reorder_level']);
            $r['expiration_status'] = InventoryService::expirationStatus($r['expiration_date']);
        }
        unset($r);

        return $rows;
    }

    public static function create(array $data, array $productIds, bool $activateNow, int $userId): int
    {
        $status = self::computeStatusToStore($data, $activateNow);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO promotions (name, description, discount_type, discount_value, start_date, end_date, status, created_by)
                 VALUES (:name, :description, :type, :value, :start, :end, :status, :uid)'
            );
            $stmt->execute([
                'name' => $data['name'], 'description' => $data['description'] ?: null,
                'type' => $data['discount_type'], 'value' => $data['discount_value'],
                'start' => $data['start_date'], 'end' => $data['end_date'],
                'status' => $status, 'uid' => $userId,
            ]);
            $id = (int) $pdo->lastInsertId();

            self::syncProducts($id, $productIds);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($userId, 'promotion.created', 'promotions', 'promotions', $id, [
            'name' => $data['name'], 'discount_type' => $data['discount_type'], 'discount_value' => $data['discount_value'],
            'status' => $status, 'product_count' => count($productIds),
        ]);

        return $id;
    }

    public static function update(int $id, array $data, array $productIds, bool $activateNow, int $userId): void
    {
        $existing = self::find($id);
        if (!$existing) {
            throw new RuntimeException('Promotion not found.');
        }

        $status = self::computeStatusToStore($data, $activateNow || $existing['status'] === 'active');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE promotions SET name = :name, description = :description, discount_type = :type,
                    discount_value = :value, start_date = :start, end_date = :end, status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                'name' => $data['name'], 'description' => $data['description'] ?: null,
                'type' => $data['discount_type'], 'value' => $data['discount_value'],
                'start' => $data['start_date'], 'end' => $data['end_date'],
                'status' => $status, 'id' => $id,
            ]);

            $oldProductIds = array_column($existing['products'], 'id');
            $productsChanged = self::syncProducts($id, $productIds);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($userId, 'promotion.updated', 'promotions', 'promotions', $id, [
            'name' => $data['name'], 'status' => $status,
        ]);
        if ($productsChanged) {
            AuditLogger::log($userId, 'promotion.products_changed', 'promotions', 'promotions', $id, [
                'old_product_ids' => $oldProductIds, 'new_product_ids' => $productIds,
            ]);
        }
    }

    /** Replaces the promotion's product set. Returns true if the set actually changed. */
    private static function syncProducts(int $promotionId, array $productIds): bool
    {
        $pdo = db();
        $existing = $pdo->prepare('SELECT product_id FROM promotion_items WHERE promotion_id = :pid');
        $existing->execute(['pid' => $promotionId]);
        $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

        sort($existingIds);
        $newIds = $productIds;
        sort($newIds);
        $changed = $existingIds !== $newIds;

        if ($changed) {
            $pdo->prepare('DELETE FROM promotion_items WHERE promotion_id = :pid')->execute(['pid' => $promotionId]);
            $insert = $pdo->prepare('INSERT INTO promotion_items (promotion_id, product_id) VALUES (:pid, :prod)');
            foreach (array_unique($productIds) as $prodId) {
                $insert->execute(['pid' => $promotionId, 'prod' => (int) $prodId]);
            }
        }

        return $changed;
    }

    /** Determines what to literally store in `status` at write time — see class docblock. */
    private static function computeStatusToStore(array $data, bool $activateNow): string
    {
        if (!$activateNow) {
            return 'inactive';
        }
        $today = date('Y-m-d');
        if ($today < $data['start_date']) return 'scheduled';
        if ($today > $data['end_date']) return 'ended';
        return 'active';
    }

    public static function setActive(int $id, bool $active, int $userId): void
    {
        $promo = self::find($id);
        if (!$promo) {
            throw new RuntimeException('Promotion not found.');
        }

        if ($active) {
            $expired = self::expiredProductNames(array_column($promo['products'], 'id'));
            if ($expired) {
                throw new RuntimeException('Cannot activate — these products are expired: ' . implode(', ', $expired) . '.');
            }
            $status = self::computeStatusToStore($promo, true);
        } else {
            $status = 'inactive';
        }

        db()->prepare('UPDATE promotions SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $id]);
        AuditLogger::log($userId, $active ? 'promotion.activated' : 'promotion.deactivated', 'promotions', 'promotions', $id, ['status' => $status]);
    }

    // ---------- Active-promotion lookup (used by POS) ----------

    /**
     * The single best currently-applicable promotion for a product, or null.
     * "Best" = highest discount_value (simple, explainable tie-break — this
     * project does not stack multiple promotions on one product).
     */
    public static function applicablePromotionForProduct(int $productId): ?array
    {
        $today = date('Y-m-d');
        $stmt = db()->prepare(
            "SELECT pr.* FROM promotions pr
             JOIN promotion_items pi ON pi.promotion_id = pr.id
             WHERE pi.product_id = :pid
               AND pr.status != 'inactive'
               AND pr.start_date <= :today1 AND pr.end_date >= :today2
             ORDER BY pr.discount_value DESC"
        );
        $stmt->execute(['pid' => $productId, 'today1' => $today, 'today2' => $today]);
        foreach ($stmt->fetchAll() as $row) {
            if (self::isCurrentlyActive($row)) {
                return $row;
            }
        }
        return null;
    }

    /** All currently-applicable promotions, keyed by product_id — one lookup for a whole cart. */
    public static function activePromotionsForProducts(array $productIds): array
    {
        if (!$productIds) return [];
        $today = date('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = db()->prepare(
            "SELECT pi.product_id, pr.* FROM promotion_items pi
             JOIN promotions pr ON pr.id = pi.promotion_id
             WHERE pi.product_id IN ($placeholders)
               AND pr.status != 'inactive'
               AND pr.start_date <= ? AND pr.end_date >= ?"
        );
        $stmt->execute(array_merge($productIds, [$today, $today]));

        $byProduct = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!self::isCurrentlyActive($row)) continue;
            $pid = (int) $row['product_id'];
            // Keep the best (highest discount_value) if a product somehow belongs to more than one active promotion.
            if (!isset($byProduct[$pid]) || (float) $row['discount_value'] > (float) $byProduct[$pid]['discount_value']) {
                $byProduct[$pid] = $row;
            }
        }
        return $byProduct;
    }

    /**
     * Computes the server-authoritative promotional discount for a POS cart.
     * $cartItems: [['product_id'=>int,'quantity'=>int,'unit_price'=>float], ...]
     * — unit_price MUST already be the authoritative DB price (the caller,
     * pos/checkout.php, re-fetches this itself; this method does not trust
     * or require any client-supplied price and does not re-query products
     * beyond resolving which promotions apply).
     *
     * @return array{discount: float, applied: array} applied = human-readable
     *   breakdown for the receipt/audit log — never trust this for the charge
     *   itself, only the 'discount' total, which OrderService::completeSale()
     *   still clamps to [0, subtotal] exactly as it already did in Stage 3.
     */
    public static function computeApplicableDiscount(array $cartItems): array
    {
        $productIds = [];
        foreach ($cartItems as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            if ($pid > 0) $productIds[] = $pid;
        }
        $promosByProduct = self::activePromotionsForProducts(array_unique($productIds));

        $totalDiscount = 0.0;
        $applied = [];

        foreach ($cartItems as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $unitPrice <= 0 || !isset($promosByProduct[$pid])) {
                continue;
            }
            $promo = $promosByProduct[$pid];
            $lineSubtotal = $unitPrice * $qty;

            if ($promo['discount_type'] === 'percentage') {
                $lineDiscount = round($lineSubtotal * ((float) $promo['discount_value'] / 100), 2);
            } else { // fixed — applied per unit, capped at the line subtotal
                $lineDiscount = round(min((float) $promo['discount_value'] * $qty, $lineSubtotal), 2);
            }
            $lineDiscount = max(0.0, min($lineDiscount, $lineSubtotal));

            $totalDiscount += $lineDiscount;
            $applied[] = [
                'promotion_id' => (int) $promo['id'], 'promotion_name' => $promo['name'],
                'product_id' => $pid, 'discount_type' => $promo['discount_type'],
                'discount_value' => (float) $promo['discount_value'], 'line_discount' => $lineDiscount,
            ];
        }

        return ['discount' => round($totalDiscount, 2), 'applied' => $applied];
    }

    // ---------- Performance (existing promo_performance table only) ----------

    /**
     * Calculates Before / During / After windows from ACTUAL completed
     * order_items for this promotion's products, and upserts them into
     * the existing `promo_performance` table (unique key: promotion_id+period).
     *
     * discount_given is an ESTIMATE, not a ledger value: Stage 3 records
     * discounts per-transaction, not per-promotion, so there is no stored
     * fact "$X of this order's discount came from this promotion" to sum.
     * Instead we reapply the promotion's own rule (percentage/fixed) to
     * the units actually sold in the "during" window — a real calculation
     * from real quantities, using the promotion's own stated terms, but
     * explicitly not a reconciled accounting figure. Before/after windows
     * report 0 discount_given since the promotion wasn't running then.
     *
     * @return array<string, array> keyed by 'before'|'during'|'after' (after
     *   is omitted if the promotion hasn't ended yet — nothing to measure).
     */
    public static function calculatePerformance(int $promotionId, int $userId): array
    {
        $promo = self::find($promotionId);
        if (!$promo) {
            throw new RuntimeException('Promotion not found.');
        }
        $productIds = array_column($promo['products'], 'id');
        if (!$productIds) {
            throw new RuntimeException('This promotion has no products to measure.');
        }

        $start = new DateTimeImmutable($promo['start_date']);
        $end = new DateTimeImmutable($promo['end_date']);
        $durationDays = max(1, $start->diff($end)->days + 1);

        $windows = [
            'before' => [$start->modify("-{$durationDays} days")->format('Y-m-d'), $start->modify('-1 day')->format('Y-m-d')],
            'during' => [$promo['start_date'], $promo['end_date']],
        ];
        $today = new DateTimeImmutable('today');
        if ($end < $today) {
            $windows['after'] = [$end->modify('+1 day')->format('Y-m-d'), $end->modify("+{$durationDays} day")->format('Y-m-d')];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $results = [];

        foreach ($windows as $period => [$periodStart, $periodEnd]) {
            $stmt = db()->prepare(
                "SELECT COUNT(DISTINCT oi.order_id) AS tx, COALESCE(SUM(oi.quantity), 0) AS units,
                        COALESCE(SUM(oi.line_total), 0) AS revenue
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE o.status = 'completed' AND DATE(o.created_at) BETWEEN ? AND ?
                   AND oi.product_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$periodStart, $periodEnd], $productIds));
            $row = $stmt->fetch();

            $tx = (int) $row['tx'];
            $units = (int) $row['units'];
            $revenue = (float) $row['revenue'];

            $discountGiven = 0.0;
            if ($period === 'during') {
                $discountGiven = $promo['discount_type'] === 'percentage'
                    ? round($revenue * ((float) $promo['discount_value'] / 100), 2)
                    : round(min((float) $promo['discount_value'] * $units, $revenue), 2);
            }
            $avgBasket = $tx > 0 ? round($units / $tx, 2) : 0.0;

            $upsert = db()->prepare(
                'INSERT INTO promo_performance (promotion_id, period, period_start, period_end, transactions_count, units_sold, revenue, discount_given, avg_basket_size)
                 VALUES (:pid, :period, :ps, :pe, :tx, :units, :revenue, :discount, :basket)
                 ON DUPLICATE KEY UPDATE period_start = :ps2, period_end = :pe2, transactions_count = :tx2,
                    units_sold = :units2, revenue = :revenue2, discount_given = :discount2, avg_basket_size = :basket2, recorded_at = NOW()'
            );
            $upsert->execute([
                'pid' => $promotionId, 'period' => $period, 'ps' => $periodStart, 'pe' => $periodEnd,
                'tx' => $tx, 'units' => $units, 'revenue' => $revenue, 'discount' => $discountGiven, 'basket' => $avgBasket,
                'ps2' => $periodStart, 'pe2' => $periodEnd, 'tx2' => $tx, 'units2' => $units,
                'revenue2' => $revenue, 'discount2' => $discountGiven, 'basket2' => $avgBasket,
            ]);

            $results[$period] = [
                'period_start' => $periodStart, 'period_end' => $periodEnd,
                'transactions_count' => $tx, 'units_sold' => $units, 'revenue' => $revenue,
                'discount_given' => $discountGiven, 'avg_basket_size' => $avgBasket,
                'has_data' => $tx > 0,
            ];
        }

        AuditLogger::log($userId, 'promotion.performance_calculated', 'promotions', 'promotions', $promotionId, [
            'periods' => array_keys($results),
        ]);

        return $results;
    }

    /** Stored performance rows for a promotion (if calculatePerformance() was ever run), keyed by period. */
    public static function storedPerformance(int $promotionId): array
    {
        $stmt = db()->prepare('SELECT * FROM promo_performance WHERE promotion_id = :pid');
        $stmt->execute(['pid' => $promotionId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['period']] = $r;
        }
        return $rows;
    }

    /** All promotions' 'during' performance, for the cross-promotion overview page. */
    public static function allDuringPerformance(): array
    {
        $stmt = db()->query(
            "SELECT pp.*, pr.name AS promotion_name, pr.discount_type, pr.discount_value, pr.start_date, pr.end_date, pr.status
             FROM promo_performance pp
             JOIN promotions pr ON pr.id = pp.promotion_id
             WHERE pp.period = 'during'
             ORDER BY pp.revenue DESC"
        );
        return $stmt->fetchAll();
    }
}

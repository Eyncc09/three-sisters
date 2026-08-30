<?php
declare(strict_types=1);

/**
 * DistributorService — Stage 5.
 * Reuses existing `distributors`, `products.primary_distributor_id`,
 * `purchase_orders` tables — no schema changes. Reorder insight
 * calculations reuse AnalyticsService::stockAnalysis() (extended in
 * Stage 4D to accept a specific product-ID list) rather than
 * re-implementing the average-daily-sales/estimated-duration math.
 */
final class DistributorService
{
    public static function all(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = 'd.name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'd.status = :status';
            $params['status'] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        $stmt = db()->prepare(
            "SELECT d.*, COUNT(DISTINCT p.id) AS product_count,
                    COUNT(DISTINCT CASE WHEN po.status NOT IN ('delivered','cancelled') THEN po.id END) AS open_po_count
             FROM distributors d
             LEFT JOIN products p ON p.primary_distributor_id = d.id AND p.status = 'active'
             LEFT JOIN purchase_orders po ON po.distributor_id = d.id
             WHERE $whereSql
             GROUP BY d.id
             ORDER BY d.name ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM distributors WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Products this distributor supplies, with live stock/expiration context. */
    public static function suppliedProducts(int $distributorId): array
    {
        $stmt = db()->prepare(
            "SELECT p.id, p.sku, p.name, p.selling_price, p.cost_price, p.reorder_level, p.expiration_date,
                    p.lead_time_days AS product_lead_time, c.name AS category_name,
                    COALESCE(i.quantity_on_hand, 0) AS current_stock
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN inventory i ON i.product_id = p.id
             WHERE p.primary_distributor_id = :did AND p.status = 'active'
             ORDER BY p.name ASC"
        );
        $stmt->execute(['did' => $distributorId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['stock_status'] = InventoryService::stockStatus((int) $r['current_stock'], (int) $r['reorder_level']);
            $r['expiration_status'] = InventoryService::expirationStatus($r['expiration_date']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Reorder insight per supplied product — reuses
     * AnalyticsService::stockAnalysis() (already has this exact math:
     * avg daily sales, estimated stock duration, lead-time comparison)
     * restricted to this distributor's products, over the last 30 days.
     */
    public static function reorderInsights(int $distributorId, int $windowDays = 30): array
    {
        $productIds = array_column(self::suppliedProducts($distributorId), 'id');
        if (!$productIds) return [];

        return AnalyticsService::stockAnalysis(
            ['date_from' => date('Y-m-d', strtotime("-{$windowDays} days")), 'date_to' => date('Y-m-d')],
            $productIds
        );
    }

    public static function validate(array $input, ?int $excludeId = null): array
    {
        $errors = [];
        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'contact_person' => trim((string) ($input['contact_person'] ?? '')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'address' => trim((string) ($input['address'] ?? '')),
            'lead_time_days' => $input['lead_time_days'] ?? '',
            'status' => in_array($input['status'] ?? '', ['active', 'inactive'], true) ? $input['status'] : 'active',
        ];

        if ($data['name'] === '') {
            $errors['name'] = 'Distributor name is required.';
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($data['lead_time_days'] === '' || !ctype_digit((string) $data['lead_time_days']) || (int) $data['lead_time_days'] < 1) {
            $errors['lead_time_days'] = 'Enter a lead time of at least 1 day.';
        }

        return ['data' => $data, 'errors' => $errors];
    }

    public static function create(array $data, int $userId): int
    {
        $stmt = db()->prepare(
            'INSERT INTO distributors (name, contact_person, phone, email, address, lead_time_days, status)
             VALUES (:name, :contact_person, :phone, :email, :address, :lead_time_days, :status)'
        );
        $stmt->execute([
            'name' => $data['name'], 'contact_person' => $data['contact_person'] ?: null,
            'phone' => $data['phone'] ?: null, 'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null, 'lead_time_days' => $data['lead_time_days'], 'status' => $data['status'],
        ]);
        $id = (int) db()->lastInsertId();
        AuditLogger::log($userId, 'distributor.created', 'distributors', 'distributors', $id, ['name' => $data['name']]);
        return $id;
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $stmt = db()->prepare(
            'UPDATE distributors SET name = :name, contact_person = :contact_person, phone = :phone,
                email = :email, address = :address, lead_time_days = :lead_time_days, status = :status WHERE id = :id'
        );
        $stmt->execute([
            'name' => $data['name'], 'contact_person' => $data['contact_person'] ?: null,
            'phone' => $data['phone'] ?: null, 'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null, 'lead_time_days' => $data['lead_time_days'],
            'status' => $data['status'], 'id' => $id,
        ]);
        AuditLogger::log($userId, 'distributor.updated', 'distributors', 'distributors', $id, ['name' => $data['name']]);
    }

    /**
     * Distributor-initiated lead-time self-update (spec section 5 — distributor
     * may update their own lead time). Narrower than update(): touches only
     * lead_time_days, nothing else, and the caller (page) must have already
     * verified this is the distributor's OWN record.
     */
    public static function updateLeadTime(int $id, int $leadTimeDays, int $userId): void
    {
        if ($leadTimeDays < 1) {
            throw new InvalidArgumentException('Lead time must be at least 1 day.');
        }
        db()->prepare('UPDATE distributors SET lead_time_days = :days WHERE id = :id')
            ->execute(['days' => $leadTimeDays, 'id' => $id]);
        AuditLogger::log($userId, 'distributor.lead_time_updated', 'distributors', 'distributors', $id, ['lead_time_days' => $leadTimeDays]);
    }
}

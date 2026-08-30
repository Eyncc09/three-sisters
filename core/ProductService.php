<?php
declare(strict_types=1);

final class ProductService
{
    public const PER_PAGE = 12;

    /**
     * Validates raw $_POST input for create/update.
     * @return array{data: array, errors: array<string,string>}
     */
    public static function validate(array $input, ?int $excludeId = null): array
    {
        $errors = [];
        $data = [
            'sku' => trim((string) ($input['sku'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'category_id' => $input['category_id'] ?? '',
            'brand_id' => $input['brand_id'] ?? '',
            'description' => trim((string) ($input['description'] ?? '')),
            'cost_price' => $input['cost_price'] ?? '',
            'selling_price' => $input['selling_price'] ?? '',
            'reorder_level' => $input['reorder_level'] ?? '',
            'expiration_date' => trim((string) ($input['expiration_date'] ?? '')),
            'distributor_id' => $input['distributor_id'] ?? '',
            'lead_time_days' => $input['lead_time_days'] ?? '',
            'status' => in_array($input['status'] ?? '', ['active', 'archived'], true) ? $input['status'] : 'active',
            'initial_stock' => $input['initial_stock'] ?? 0,
        ];

        if ($data['sku'] === '') {
            $errors['sku'] = 'SKU is required.';
        } elseif (!preg_match('/^[A-Za-z0-9\-_.]{2,50}$/', $data['sku'])) {
            $errors['sku'] = 'SKU may only contain letters, numbers, hyphens, underscores, and periods.';
        } elseif (self::skuExists($data['sku'], $excludeId)) {
            $errors['sku'] = 'This SKU is already in use.';
        }

        if ($data['name'] === '') {
            $errors['name'] = 'Product name is required.';
        }

        if ($data['cost_price'] === '' || !is_numeric($data['cost_price']) || (float) $data['cost_price'] < 0) {
            $errors['cost_price'] = 'Enter a valid cost price.';
        }
        if ($data['selling_price'] === '' || !is_numeric($data['selling_price']) || (float) $data['selling_price'] < 0) {
            $errors['selling_price'] = 'Enter a valid selling price.';
        }
        if ($data['reorder_level'] === '' || !ctype_digit((string) $data['reorder_level'])) {
            $errors['reorder_level'] = 'Reorder level must be a whole number.';
        }
        if ($data['expiration_date'] !== '' && !DateTime::createFromFormat('Y-m-d', $data['expiration_date'])) {
            $errors['expiration_date'] = 'Enter a valid date.';
        }
        if (!ctype_digit((string) $data['initial_stock'])) {
            $data['initial_stock'] = 0;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /** @return array{items: array, total: int, page: int, pages: int} */
    public static function search(array $filters, int $page = 1): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE :q OR p.sku LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['brand_id'])) {
            $where[] = 'p.brand_id = :brand_id';
            $params['brand_id'] = (int) $filters['brand_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        } else {
            $where[] = "p.status != 'archived'";
        }
        if (!empty($filters['stock_status'])) {
            // Filtered in PHP after fetch since it's a computed value — dataset is small enough for a capstone system.
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $countStmt = db()->prepare("SELECT COUNT(*) FROM products p WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT p.*, c.name AS category_name, b.name AS brand_name, d.name AS distributor_name,
                       COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN brands b ON b.id = p.brand_id
                LEFT JOIN distributors d ON d.id = p.primary_distributor_id
                LEFT JOIN inventory i ON i.product_id = p.id
                WHERE $whereSql
                ORDER BY p.name ASC
                LIMIT " . self::PER_PAGE . " OFFSET $offset";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['stock_status'] = InventoryService::stockStatus((int) $item['quantity_on_hand'], (int) $item['reorder_level']);
            $item['expiration_status'] = InventoryService::expirationStatus($item['expiration_date']);
        }
        unset($item);

        if (!empty($filters['stock_status'])) {
            $items = array_values(array_filter($items, fn($i) => $i['stock_status'] === $filters['stock_status']));
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT p.*, c.name AS category_name, b.name AS brand_name, d.name AS distributor_name,
                    d.lead_time_days AS distributor_lead_time, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN distributors d ON d.id = p.primary_distributor_id
             LEFT JOIN inventory i ON i.product_id = p.id
             WHERE p.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['stock_status'] = InventoryService::stockStatus((int) $row['quantity_on_hand'], (int) $row['reorder_level']);
        $row['expiration_status'] = InventoryService::expirationStatus($row['expiration_date']);
        return $row;
    }

    public static function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE sku = :sku';
        $params = ['sku' => $sku];
        if ($excludeId !== null) { $sql .= ' AND id != :id'; $params['id'] = $excludeId; }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Creates a product. If an initial stock quantity is given, records it
     * as a `stock_in` movement through InventoryService (never writes
     * inventory directly), so the audit trail stays complete from day one.
     */
    public static function create(array $data, int $userId): int
    {
        $stmt = db()->prepare(
            'INSERT INTO products
                (sku, name, category_id, brand_id, description, cost_price, selling_price,
                 reorder_level, expiration_date, primary_distributor_id, lead_time_days, status)
             VALUES
                (:sku, :name, :category_id, :brand_id, :description, :cost_price, :selling_price,
                 :reorder_level, :expiration_date, :distributor_id, :lead_time_days, :status)'
        );
        $stmt->execute([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?: null,
            'brand_id' => $data['brand_id'] ?: null,
            'description' => $data['description'] ?: null,
            'cost_price' => $data['cost_price'],
            'selling_price' => $data['selling_price'],
            'reorder_level' => $data['reorder_level'],
            'expiration_date' => $data['expiration_date'] ?: null,
            'distributor_id' => $data['distributor_id'] ?: null,
            'lead_time_days' => $data['lead_time_days'] ?: null,
            'status' => $data['status'] ?? 'active',
        ]);
        $id = (int) db()->lastInsertId();

        InventoryService::ensureRow($id);
        if (!empty($data['initial_stock']) && (int) $data['initial_stock'] > 0) {
            InventoryService::stockIn($id, (int) $data['initial_stock'], 'Initial stock on product creation', $userId);
        }

        AuditLogger::log($userId, 'product.created', 'products', 'products', $id, ['sku' => $data['sku'], 'name' => $data['name']]);
        return $id;
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $stmt = db()->prepare(
            'UPDATE products SET
                sku = :sku, name = :name, category_id = :category_id, brand_id = :brand_id,
                description = :description, cost_price = :cost_price, selling_price = :selling_price,
                reorder_level = :reorder_level, expiration_date = :expiration_date,
                primary_distributor_id = :distributor_id, lead_time_days = :lead_time_days, status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?: null,
            'brand_id' => $data['brand_id'] ?: null,
            'description' => $data['description'] ?: null,
            'cost_price' => $data['cost_price'],
            'selling_price' => $data['selling_price'],
            'reorder_level' => $data['reorder_level'],
            'expiration_date' => $data['expiration_date'] ?: null,
            'distributor_id' => $data['distributor_id'] ?: null,
            'lead_time_days' => $data['lead_time_days'] ?: null,
            'status' => $data['status'],
            'id' => $id,
        ]);
        AuditLogger::log($userId, 'product.updated', 'products', 'products', $id, ['sku' => $data['sku']]);
        InventoryService::refreshLowStockAlert($id);
    }

    public static function setStatus(int $id, string $status, int $userId): void
    {
        db()->prepare('UPDATE products SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $id]);
        AuditLogger::log($userId, 'product.' . $status, 'products', 'products', $id);
    }
}

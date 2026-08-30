<?php
declare(strict_types=1);

final class CustomerService
{
    public const PER_PAGE = 15;

    public static function search(string $q = '', int $page = 1): array
    {
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where = '(full_name LIKE :q OR phone LIKE :q OR email LIKE :q)';
            $params['q'] = "%$q%";
        }
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $countStmt = db()->prepare("SELECT COUNT(*) FROM customers WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = db()->prepare("SELECT * FROM customers WHERE $where ORDER BY full_name ASC LIMIT " . self::PER_PAGE . " OFFSET $offset");
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Purchase summary for a customer's profile page. Reads from `orders`/
     * `order_items` — safe to call even before POS (Stage 3) exists since
     * it simply returns zeros with no matching rows.
     */
    public static function purchaseSummary(int $customerId): array
    {
        $stmt = db()->prepare(
            "SELECT COUNT(DISTINCT o.id) AS total_orders,
                    COALESCE(SUM(o.total_amount), 0) AS total_spent,
                    MAX(o.created_at) AS last_purchase
             FROM orders o WHERE o.customer_id = :cid AND o.status = 'completed'"
        );
        $stmt->execute(['cid' => $customerId]);
        return $stmt->fetch() ?: ['total_orders' => 0, 'total_spent' => 0, 'last_purchase' => null];
    }

    public static function favoriteProducts(int $customerId, int $limit = 5): array
    {
        $stmt = db()->prepare(
            "SELECT p.name, SUM(oi.quantity) AS units
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN products p ON p.id = oi.product_id
             WHERE o.customer_id = :cid AND o.status = 'completed'
             GROUP BY p.id, p.name ORDER BY units DESC LIMIT $limit"
        );
        $stmt->execute(['cid' => $customerId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data, int $userId): int
    {
        $stmt = db()->prepare('INSERT INTO customers (full_name, phone, email, address) VALUES (:full_name, :phone, :email, :address)');
        $stmt->execute([
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
        ]);
        $id = (int) db()->lastInsertId();
        AuditLogger::log($userId, 'customer.created', 'customers', 'customers', $id, ['full_name' => $data['full_name']]);
        return $id;
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $stmt = db()->prepare('UPDATE customers SET full_name = :full_name, phone = :phone, email = :email, address = :address WHERE id = :id');
        $stmt->execute([
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'id' => $id,
        ]);
        AuditLogger::log($userId, 'customer.updated', 'customers', 'customers', $id);
    }
}

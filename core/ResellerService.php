<?php
declare(strict_types=1);

final class ResellerService
{
    public const PER_PAGE = 15;

    public static function search(string $q = '', ?string $status = null, int $page = 1): array
    {
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(full_name LIKE :q OR business_name LIKE :q OR phone LIKE :q)';
            $params['q'] = "%$q%";
        }
        if ($status) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $countStmt = db()->prepare("SELECT COUNT(*) FROM resellers WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = db()->prepare("SELECT * FROM resellers WHERE $whereSql ORDER BY full_name ASC LIMIT " . self::PER_PAGE . " OFFSET $offset");
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
        $stmt = db()->prepare('SELECT * FROM resellers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function purchaseSummary(int $resellerId): array
    {
        $stmt = db()->prepare(
            "SELECT COUNT(DISTINCT o.id) AS total_orders,
                    COALESCE(SUM(o.total_amount), 0) AS total_spent,
                    MAX(o.created_at) AS last_order
             FROM orders o WHERE o.reseller_id = :rid AND o.status = 'completed'"
        );
        $stmt->execute(['rid' => $resellerId]);
        return $stmt->fetch() ?: ['total_orders' => 0, 'total_spent' => 0, 'last_order' => null];
    }

    public static function favoriteProducts(int $resellerId, int $limit = 5): array
    {
        $stmt = db()->prepare(
            "SELECT p.name, SUM(oi.quantity) AS units
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN products p ON p.id = oi.product_id
             WHERE o.reseller_id = :rid AND o.status = 'completed'
             GROUP BY p.id, p.name ORDER BY units DESC LIMIT $limit"
        );
        $stmt->execute(['rid' => $resellerId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data, int $userId): int
    {
        $stmt = db()->prepare(
            'INSERT INTO resellers (full_name, business_name, phone, email, address, registration_date, status)
             VALUES (:full_name, :business_name, :phone, :email, :address, :registration_date, :status)'
        );
        $stmt->execute([
            'full_name' => $data['full_name'],
            'business_name' => $data['business_name'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'registration_date' => $data['registration_date'] ?: date('Y-m-d'),
            'status' => $data['status'] ?? 'active',
        ]);
        $id = (int) db()->lastInsertId();
        AuditLogger::log($userId, 'reseller.created', 'resellers', 'resellers', $id, ['full_name' => $data['full_name']]);
        return $id;
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $stmt = db()->prepare(
            'UPDATE resellers SET full_name = :full_name, business_name = :business_name, phone = :phone,
                email = :email, address = :address, status = :status WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $data['full_name'],
            'business_name' => $data['business_name'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'status' => $data['status'],
            'id' => $id,
        ]);
        AuditLogger::log($userId, 'reseller.updated', 'resellers', 'resellers', $id);
    }
}

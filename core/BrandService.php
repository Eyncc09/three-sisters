<?php
declare(strict_types=1);

final class BrandService
{
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM brands';
        if ($activeOnly) $sql .= " WHERE status = 'active'";
        $sql .= ' ORDER BY name ASC';
        return db()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM brands WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM brands WHERE name = :name';
        $params = ['name' => $name];
        if ($excludeId !== null) { $sql .= ' AND id != :id'; $params['id'] = $excludeId; }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(array $data, int $userId): int
    {
        $stmt = db()->prepare('INSERT INTO brands (name, description, status) VALUES (:name, :description, :status)');
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'status' => $data['status'] ?? 'active',
        ]);
        $id = (int) db()->lastInsertId();
        AuditLogger::log($userId, 'brand.created', 'products', 'brands', $id, ['name' => $data['name']]);
        return $id;
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $stmt = db()->prepare('UPDATE brands SET name = :name, description = :description, status = :status WHERE id = :id');
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'status' => $data['status'],
            'id' => $id,
        ]);
        AuditLogger::log($userId, 'brand.updated', 'products', 'brands', $id, $data);
    }
}

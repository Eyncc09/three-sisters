<?php
declare(strict_types=1);

final class PaymentService
{
    public static function pendingPayments(int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $total = (int) db()->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();

        $stmt = db()->prepare(
            "SELECT p.*, o.order_number, o.total_amount, c.full_name AS customer_name, r.full_name AS reseller_name,
                    (SELECT COUNT(*) FROM payment_proofs pp WHERE pp.payment_id = p.id) AS proof_count
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN resellers r ON r.id = o.reseller_id
             WHERE p.status = 'pending'
             ORDER BY p.created_at ASC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function find(int $paymentId): ?array
    {
        $stmt = db()->prepare(
            "SELECT p.*, o.order_number, o.total_amount, c.full_name AS customer_name, r.full_name AS reseller_name
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN resellers r ON r.id = o.reseller_id
             WHERE p.id = :id"
        );
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) return null;

        $proofStmt = db()->prepare('SELECT * FROM payment_proofs WHERE payment_id = :pid ORDER BY id DESC');
        $proofStmt->execute(['pid' => $paymentId]);
        $payment['proofs'] = $proofStmt->fetchAll();

        return $payment;
    }

    public static function verify(int $paymentId, int $userId): void
    {
        db()->prepare("UPDATE payments SET status = 'verified', verified_by = :uid, verified_at = NOW() WHERE id = :id")
            ->execute(['uid' => $userId, 'id' => $paymentId]);
        AuditLogger::log($userId, 'payment.verified', 'payments', 'payments', $paymentId);
    }

    public static function reject(int $paymentId, int $userId, string $reason = ''): void
    {
        db()->prepare("UPDATE payments SET status = 'rejected', verified_by = :uid, verified_at = NOW() WHERE id = :id")
            ->execute(['uid' => $userId, 'id' => $paymentId]);
        AuditLogger::log($userId, 'payment.rejected', 'payments', 'payments', $paymentId, ['reason' => $reason]);
    }
}

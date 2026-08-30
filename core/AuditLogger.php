<?php
/**
 * AuditLogger — writes to audit_logs (spec section 31).
 * Every module's service classes should call AuditLogger::log() after any
 * create/update/delete of a business record, rather than writing to
 * audit_logs directly, so the log format stays consistent app-wide.
 */

declare(strict_types=1);

final class AuditLogger
{
    public static function log(
        ?int $userId,
        string $action,
        string $module,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?array $details = null
    ): void {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, module, related_type, related_id, details, ip_address)
             VALUES (:user_id, :action, :module, :related_type, :related_id, :details, :ip_address)'
        );

        $stmt->execute([
            'user_id'      => $userId,
            'action'       => $action,
            'module'       => $module,
            'related_type' => $relatedType,
            'related_id'   => $relatedId,
            'details'      => $details ? json_encode($details) : null,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

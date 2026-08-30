<?php
declare(strict_types=1);

/**
 * ChatService — Stage 5 Owner<->Distributor communication.
 * Reuses existing `chat_conversations`/`chat_messages` tables exactly as
 * designed in Phase 1 — no schema changes. A plain database-backed
 * conversation (page reload to see new messages) — no websockets/polling
 * infrastructure, per the spec's explicit "keep it simple" instruction.
 *
 * `chat_conversations.owner_id` is a single user row (schema requirement),
 * but "the Owner" is really the shop, not one specific login — see
 * primaryOwnerUserId() for how a distributor-initiated conversation picks
 * a sensible default.
 */
final class ChatService
{
    /** First active Owner-role account — used as the default counterpart when a Distributor opens a conversation. */
    public static function primaryOwnerUserId(): ?int
    {
        $stmt = db()->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.name = 'owner' AND u.status = 'active' ORDER BY u.id ASC LIMIT 1"
        );
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Finds or creates the (single, general) conversation for a distributor. */
    public static function getOrCreateConversation(int $distributorId): ?int
    {
        $stmt = db()->prepare('SELECT id FROM chat_conversations WHERE distributor_id = :did ORDER BY id ASC LIMIT 1');
        $stmt->execute(['did' => $distributorId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ownerId = self::primaryOwnerUserId();
        if (!$ownerId) {
            return null; // no owner account exists yet — shouldn't happen in practice
        }

        $stmt = db()->prepare('INSERT INTO chat_conversations (distributor_id, owner_id) VALUES (:did, :oid)');
        $stmt->execute(['did' => $distributorId, 'oid' => $ownerId]);
        return (int) db()->lastInsertId();
    }

    /** Conversations list for the Owner side — one row per distributor, with last message preview + unread count. */
    public static function conversationsForOwner(): array
    {
        $stmt = db()->query(
            "SELECT cc.id AS conversation_id, cc.distributor_id, d.name AS distributor_name, cc.updated_at,
                    (SELECT message FROM chat_messages cm WHERE cm.conversation_id = cc.id ORDER BY cm.created_at DESC LIMIT 1) AS last_message,
                    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.id AND cm.is_read = 0 AND cm.sender_id != cc.owner_id) AS unread_count
             FROM chat_conversations cc
             JOIN distributors d ON d.id = cc.distributor_id
             ORDER BY cc.updated_at DESC"
        );
        return $stmt->fetchAll();
    }

    public static function messages(int $conversationId, int $limit = 100): array
    {
        $stmt = db()->prepare(
            'SELECT cm.*, u.full_name AS sender_name, u.role_id
             FROM chat_messages cm JOIN users u ON u.id = cm.sender_id
             WHERE cm.conversation_id = :cid
             ORDER BY cm.created_at ASC LIMIT ' . (int) $limit
        );
        $stmt->execute(['cid' => $conversationId]);
        return $stmt->fetchAll();
    }

    public static function sendMessage(int $conversationId, int $senderId, string $message): int
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Message cannot be empty.');
        }
        if (mb_strlen($message) > 2000) {
            $message = mb_substr($message, 0, 2000);
        }

        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (:cid, :sid, :msg)');
        $stmt->execute(['cid' => $conversationId, 'sid' => $senderId, 'msg' => $message]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE chat_conversations SET updated_at = NOW() WHERE id = :cid')->execute(['cid' => $conversationId]);

        AuditLogger::log($senderId, 'distributor.message_sent', 'distributors', 'chat_conversations', $conversationId, ['message_id' => $id]);

        return $id;
    }

    /** Marks all messages in a conversation NOT sent by $viewerId as read. */
    public static function markRead(int $conversationId, int $viewerId): void
    {
        db()->prepare('UPDATE chat_messages SET is_read = 1 WHERE conversation_id = :cid AND sender_id != :viewer AND is_read = 0')
            ->execute(['cid' => $conversationId, 'viewer' => $viewerId]);
    }

    /** Unread message count across all conversations relevant to this user — for dashboard/nav badges. */
    public static function unreadCountForUser(int $userId, string $role, ?int $distributorId = null): int
    {
        if ($role === ROLE_DISTRIBUTOR && $distributorId) {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM chat_messages cm
                 JOIN chat_conversations cc ON cc.id = cm.conversation_id
                 WHERE cc.distributor_id = :did AND cm.sender_id != :uid AND cm.is_read = 0"
            );
            $stmt->execute(['did' => $distributorId, 'uid' => $userId]);
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM chat_messages cm WHERE cm.sender_id != :uid AND cm.is_read = 0');
            $stmt->execute(['uid' => $userId]);
        }
        return (int) $stmt->fetchColumn();
    }
}

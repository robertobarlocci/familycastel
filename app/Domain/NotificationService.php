<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/** In-app notifications (parents: pending approvals; children: outcomes). */
final class NotificationService
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed>|null $payload */
    public function notify(string $recipientType, int $recipientId, string $type, ?array $payload = null): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'INSERT INTO notifications (recipient_type, recipient_id, type, payload, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$recipientType, $recipientId, $type, $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR)]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function unreadFor(string $recipientType, int $recipientId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM notifications
             WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL
             ORDER BY id DESC LIMIT ?',
            [$recipientType, $recipientId, $limit]
        );
    }

    public function markAllRead(string $recipientType, int $recipientId): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL',
            [$recipientType, $recipientId]
        ));
    }

    /** Pending-approval counters for the parent header badge. */
    public function pendingCounts(): array
    {
        return [
            'sidequests' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM sidequest_claims WHERE status = 'completed_pending'"
            )['c'],
            'suggestions' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM suggestions WHERE status = 'pending'"
            )['c'],
            'rewards' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM reward_requests WHERE status = 'pending'"
            )['c'],
            'wishes' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM milestone_requests WHERE status = 'pending'"
            )['c'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/** Append-only administrative event log (troubleshooting/security). */
final class AuditService
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed>|null $details */
    public function log(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $details = null,
        ?string $ip = null,
    ): void {
        $this->db->execute(
            'INSERT INTO audit_log (actor_type, actor_id, action, subject_type, subject_id, details, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $actorType,
                $actorId,
                $action,
                $subjectType,
                $subjectId,
                $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
                $ip,
            ]
        );
    }
}

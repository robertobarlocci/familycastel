<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/**
 * Children management. Children are archived, never deleted (INV-001) —
 * their ledger, claims and achievements stay forever. Balances/XP are never
 * touched here (INV-002: LedgerService only).
 */
final class ChildService
{
    public const THEMES = ['fantasy', 'football'];
    public const CHARACTERS = [
        'fantasy' => ['knight', 'archer', 'mage', 'paladin'],
        'football' => ['striker', 'goalkeeper', 'defender', 'midfielder'],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array{name: string, theme?: string, character_key?: string} $data */
    public function create(array $data): int
    {
        [$name, $theme, $character] = $this->validated($data);

        return WriteGate::transaction($this->db, function (Db $db) use ($name, $theme, $character): int {
            $db->execute(
                'INSERT INTO children (name, theme, character_key, allowed_themes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$name, $theme, $character, json_encode(self::THEMES, JSON_THROW_ON_ERROR)]
            );

            return $db->lastInsertId();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->assertExists($id);
        [$name, $theme, $character] = $this->validated($data);
        $sound = !empty($data['sound_enabled']) ? 1 : 0;

        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE children SET name = ?, theme = ?, character_key = ?, sound_enabled = ?,
                                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?',
            [$name, $theme, $character, $sound, $id]
        ));
    }

    public function setPin(int $id, string $pin): void
    {
        $this->assertExists($id);
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            throw new \InvalidArgumentException('PIN must be 4-6 digits.');
        }

        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE children SET pin_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [password_hash($pin, PASSWORD_DEFAULT), $id]
        ));
    }

    public function clearPin(int $id): void
    {
        $this->assertExists($id);
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE children SET pin_hash = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$id]
        ));
    }

    /** Archive (INV-001: never delete). Also revokes all QR tokens. */
    public function archive(int $id): void
    {
        $this->assertExists($id);
        WriteGate::transaction($this->db, function (Db $db) use ($id): void {
            $db->execute(
                'UPDATE children SET archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND archived_at IS NULL',
                [$id]
            );
            $db->execute(
                'UPDATE auth_tokens SET revoked_at = UTC_TIMESTAMP() WHERE child_id = ? AND revoked_at IS NULL',
                [$id]
            );
        });
    }

    public function unarchive(int $id): void
    {
        $this->assertExists($id);
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'UPDATE children SET archived_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$id]
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM children WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM children WHERE archived_at IS NULL ORDER BY name'
        );
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->db->fetchAll('SELECT * FROM children ORDER BY archived_at IS NULL DESC, name');
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function validated(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Child name is required (max 100 characters).');
        }

        $theme = (string) ($data['theme'] ?? 'fantasy');
        if (!in_array($theme, self::THEMES, true)) {
            throw new \InvalidArgumentException('Unknown theme.');
        }

        $character = (string) ($data['character_key'] ?? self::CHARACTERS[$theme][0]);
        if (!in_array($character, self::CHARACTERS[$theme], true)) {
            $character = self::CHARACTERS[$theme][0];
        }

        return [$name, $theme, $character];
    }

    private function assertExists(int $id): void
    {
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('Child not found.');
        }
    }
}

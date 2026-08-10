<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

use FamilyCastel\Core\Db;

/** Typed access to the settings KV table (JSON values), request-cached. */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        WriteGate::transaction($this->db, fn (Db $db) => $db->execute(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP()',
            [$key, json_encode($value, JSON_THROW_ON_ERROR)]
        ));
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->db->fetchAll('SELECT `key`, `value` FROM settings') as $row) {
            $this->cache[$row['key']] = json_decode($row['value'], true);
        }
    }
}

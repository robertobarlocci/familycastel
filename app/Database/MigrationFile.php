<?php

declare(strict_types=1);

namespace FamilyCastel\Database;

use FamilyCastel\Core\Db;

final class MigrationFile
{
    private readonly string $version;
    private readonly string $name;

    public function __construct(private readonly string $path)
    {
        $base = basename($this->path, '.php');
        [$version, $name] = explode('_', $base, 2) + [1 => ''];
        $this->version = $version;
        $this->name = $name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function instantiate(Db $db): Migration
    {
        $migration = require $this->path;
        if ($migration instanceof \Closure) {
            $migration = $migration($db);
        }
        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration file {$this->path} must return a Migration instance factory");
        }

        return $migration;
    }
}

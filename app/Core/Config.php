<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

final class Config
{
    /** @var array<string, mixed> */
    private array $values = [];
    private bool $loaded = false;

    public function __construct(private readonly string $configDir)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->configDir . '/config.php')
            && is_file($this->configDir . '/installed.lock');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $file = $this->configDir . '/config.php';
        if (is_file($file)) {
            $values = require $file;
            if (is_array($values)) {
                $this->values = $values;
            }
        }
    }
}

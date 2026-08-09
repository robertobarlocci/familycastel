<?php

declare(strict_types=1);

namespace FamilyCastel\Install;

/**
 * Wizard state persisted in storage/install-state.json (HTTP-denied dir).
 * Expires after a few hours so half-finished installs are cleaned up on
 * re-entry (Matomo lesson). Also owns the filesystem-ownership setup token.
 *
 * Concurrency: every read-modify-write cycle runs under an exclusive flock on
 * a dedicated lock file, and mutations re-read the state from disk first —
 * concurrent requests can never regress steps or resurrect stale snapshots.
 * Sensitive files are chmod 0600 fail-CLOSED (an unverifiable chmod aborts).
 */
final class InstallState
{
    private const STATE_FILE = 'install-state.json';
    private const TOKEN_FILE = 'setup-token.txt';
    private const LOCK_FILE = 'install-state.lock';
    private const DEFAULT_TTL = 4 * 3600;
    private const MAX_TOKEN_ATTEMPTS = 10;

    /** @var array{started_at: int, steps: list<string>, data: array<string, mixed>} */
    private array $state;

    public function __construct(
        private readonly string $storageDir,
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
    ) {
        $this->state = $this->loadOrReset();
    }

    public function get(string $key): mixed
    {
        return $this->state['data'][$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->mutate(function (array $state) use ($key, $value): array {
            $state['data'][$key] = $value;

            return $state;
        });
    }

    public function markStep(string $step): void
    {
        $this->mutate(function (array $state) use ($step): array {
            if (!in_array($step, $state['steps'], true)) {
                $state['steps'][] = $step;
            }

            return $state;
        });
    }

    /** @return list<string> */
    public function completedSteps(): array
    {
        return $this->state['steps'];
    }

    /**
     * Remove all wizard artifacts. Returns the paths that could NOT be removed
     * (caller must surface those loudly — they may contain credentials).
     *
     * @return list<string>
     */
    public function destroy(): array
    {
        // Unlink under the lock so an in-flight mutation cannot recreate the
        // files afterwards. The lock file itself is deliberately KEPT —
        // unlinking a locked file splits the lock inode on Unix (two processes
        // could then hold "the" lock simultaneously). It is empty and
        // HTTP-denied; harmless.
        $leftovers = $this->withLock(function (): array {
            $left = [];
            foreach ([self::STATE_FILE, self::TOKEN_FILE] as $name) {
                $path = $this->storageDir . '/' . $name;
                if (is_file($path) && !@unlink($path)) {
                    $left[] = $path;
                }
            }

            return $left;
        });
        $this->state = $this->freshState();

        return $leftovers;
    }

    /**
     * Create (once) the setup code the user must read via FTP/file manager —
     * proof of filesystem ownership. Only called AFTER the HTTP-protection
     * probe passed, so the file can never be web-readable.
     */
    public function ensureSetupToken(): string
    {
        return $this->withLock(function (): string {
            $file = $this->storageDir . '/' . self::TOKEN_FILE;
            if (is_file($file)) {
                $existing = trim((string) file_get_contents($file));
                if ($existing !== '') {
                    return $existing;
                }
            }

            $code = $this->generateCode();
            $this->atomicWrite($file, $code . PHP_EOL);

            return $code;
        });
    }

    /**
     * Constant-time verification with attempt throttling: after 10 failures
     * the token is regenerated (old code worthless) and the counter reset —
     * the legitimate owner just reads the new file; a guesser starts over.
     */
    public function verifySetupToken(?string $input): bool
    {
        $file = $this->storageDir . '/' . self::TOKEN_FILE;
        if (!is_file($file) || $input === null || $input === '') {
            return false;
        }

        $expected = trim((string) file_get_contents($file));
        if ($expected !== '' && hash_equals($expected, strtoupper(trim($input)))) {
            return true;
        }

        $this->registerFailedAttempt($file);

        return false;
    }

    /**
     * Bind the wizard to the browser session that proved filesystem ownership.
     * Returns the binding secret to store in that session; later steps must
     * present it (sessionMatches) — another client cannot hijack the wizard.
     */
    public function bindSession(): string
    {
        $binding = bin2hex(random_bytes(32));
        $this->set('session_binding', $binding);

        return $binding;
    }

    public function sessionMatches(?string $binding): bool
    {
        $expected = $this->get('session_binding');

        return is_string($expected) && is_string($binding) && $binding !== ''
            && hash_equals($expected, $binding);
    }

    private function registerFailedAttempt(string $tokenFile): void
    {
        $this->mutate(function (array $state) use ($tokenFile): array {
            $attempts = (int) ($state['data']['token_attempts'] ?? 0) + 1;
            if ($attempts >= self::MAX_TOKEN_ATTEMPTS) {
                $this->atomicWrite($tokenFile, $this->generateCode() . PHP_EOL);
                $attempts = 0;
            }
            $state['data']['token_attempts'] = $attempts;

            return $state;
        });
    }

    private function generateCode(): string
    {
        // 8 chars from an unambiguous alphabet (no 0/O/1/I) — typed by hand.
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** @param callable(array): array $fn */
    private function mutate(callable $fn): void
    {
        $this->withLock(function () use ($fn): void {
            $this->state = $fn($this->loadOrReset());
            $this->atomicWrite(
                $this->storageDir . '/' . self::STATE_FILE,
                json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );
        });
    }

    private bool $lockHeld = false;

    private function withLock(callable $fn): mixed
    {
        // Reentrant per instance: mutate() → loadOrReset() → destroy() on TTL
        // expiry must not deadlock on its own flock.
        if ($this->lockHeld) {
            return $fn();
        }

        $handle = fopen($this->storageDir . '/' . self::LOCK_FILE, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Cannot acquire installer lock');
        }

        $this->lockHeld = true;
        try {
            return $fn();
        } finally {
            $this->lockHeld = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{started_at: int, steps: list<string>, data: array<string, mixed>} */
    private function loadOrReset(): array
    {
        $file = $this->storageDir . '/' . self::STATE_FILE;
        if (!is_file($file)) {
            return $this->freshState();
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['started_at'])) {
            return $this->freshState();
        }

        if (time() - (int) $decoded['started_at'] > $this->ttlSeconds) {
            // Expired half-finished install: discard state AND token.
            $this->destroy();

            return $this->freshState();
        }

        return [
            'started_at' => (int) $decoded['started_at'],
            'steps' => array_values(array_filter((array) ($decoded['steps'] ?? []), is_string(...))),
            'data' => (array) ($decoded['data'] ?? []),
        ];
    }

    /** @return array{started_at: int, steps: list<string>, data: array<string, mixed>} */
    private function freshState(): array
    {
        return ['started_at' => time(), 'steps' => [], 'data' => []];
    }

    private function atomicWrite(string $file, string $contents): void
    {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write to storage directory');
        }
        // Fail CLOSED: credentials/tokens must never keep umask permissions.
        if (!@chmod($tmp, 0600)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot restrict file permissions in storage directory');
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot finalize write in storage directory');
        }
    }
}

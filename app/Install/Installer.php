<?php

declare(strict_types=1);

namespace FamilyCastel\Install;

use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migrator;

/**
 * Executes the actual installation: validates, writes config atomically, runs
 * migrations, creates the first parent, seeds settings, writes the install
 * lock and consumes the CAN_INSTALL marker (double gate — Nextcloud pattern).
 */
final class Installer
{
    private const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private readonly string $configDir,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * @param array{
     *   db: array{host: string, port: int, name: string, user: string, password: string, prefix: string},
     *   family: array{name: string, locale: string, timezone: string},
     *   parent: array{name: string, username: string, password: string},
     *   demo?: bool
     * } $params
     */
    public function perform(array $params): void
    {
        $this->assertInstallable();
        $this->validate($params);

        $db = Db::fromParams(
            host: $params['db']['host'],
            port: $params['db']['port'],
            name: $params['db']['name'],
            user: $params['db']['user'],
            password: $params['db']['password'],
        );

        (new Migrator($db, $this->migrationsDir))->migrate();

        $parentId = $this->createParent($db, $params['parent']);
        $this->seedSettings($db, $params['family']);

        if (!empty($params['demo'])) {
            // Demo data is optional decoration — a seeding failure must NEVER
            // fail or half-break the installation itself.
            try {
                (new \FamilyCastel\Domain\DemoSeeder($db))->run($parentId);
            } catch (\Throwable $e) {
                \FamilyCastel\Core\ErrorHandler::log(dirname($this->configDir) . '/storage/logs', $e);
            }
        }

        $secret = bin2hex(random_bytes(32));
        $this->writeConfig($params, $secret);
        $this->writeLock($secret);

        // Consume the shipped marker; warn loudly upstream if this fails.
        if (is_file($this->configDir . '/CAN_INSTALL') && !@unlink($this->configDir . '/CAN_INSTALL')) {
            throw new \RuntimeException(
                'Installation succeeded, but config/CAN_INSTALL could not be deleted. '
                . 'Delete it manually via your hosting file manager NOW — it must not remain on the server.'
            );
        }
    }

    public function assertInstallable(): void
    {
        if (is_file($this->configDir . '/installed.lock')) {
            throw new \RuntimeException('Family Castel is already installed.');
        }
        if (!is_file($this->configDir . '/CAN_INSTALL')) {
            throw new \RuntimeException(
                'The CAN_INSTALL marker is missing. To (re)install, create an empty file '
                . 'config/CAN_INSTALL via your hosting file manager first.'
            );
        }
    }

    /** @param array<string, mixed> $params */
    private function validate(array $params): void
    {
        foreach (['db', 'family', 'parent'] as $section) {
            if (!isset($params[$section]) || !is_array($params[$section])) {
                throw new \InvalidArgumentException("Missing installer section: {$section}");
            }
        }

        $parent = $params['parent'];
        if (trim((string) ($parent['name'] ?? '')) === ''
            || trim((string) ($parent['username'] ?? '')) === '') {
            throw new \InvalidArgumentException('Parent name and username are required.');
        }
        if (strlen((string) ($parent['password'] ?? '')) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Parent password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }

        $family = $params['family'];
        if (trim((string) ($family['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('Family name is required.');
        }
        if (!in_array($family['locale'] ?? '', ['de', 'en', 'fr', 'it'], true)) {
            throw new \InvalidArgumentException('Unsupported locale.');
        }
        if (!in_array($family['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid timezone.');
        }
    }

    /** @param array{name: string, username: string, password: string} $parent */
    private function createParent(Db $db, array $parent): int
    {
        // Idempotent for retries after a partial earlier run — but ONLY when
        // the existing row is provably the account just requested (password
        // verifies). Any other pre-existing user with this username means the
        // database is not fresh: fail loudly instead of adopting a stranger.
        $existing = $db->fetchOne(
            'SELECT id, password_hash FROM users WHERE username = ?',
            [strtolower(trim($parent['username']))]
        );
        if ($existing !== null) {
            if (!password_verify($parent['password'], (string) $existing['password_hash'])) {
                throw new \RuntimeException(
                    'A different account with this username already exists in this database. '
                    . 'Use an empty database or another username.'
                );
            }

            return (int) $existing['id'];
        }

        $db->execute(
            'INSERT INTO users (name, username, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                trim($parent['name']),
                strtolower(trim($parent['username'])),
                filter_var($parent['username'], FILTER_VALIDATE_EMAIL) ? strtolower(trim($parent['username'])) : null,
                password_hash($parent['password'], PASSWORD_DEFAULT),
                'parent',
            ]
        );

        return $db->lastInsertId();
    }

    /** @param array{name: string, locale: string, timezone: string} $family */
    private function seedSettings(Db $db, array $family): void
    {
        $defaults = [
            'app.version' => trim((string) @file_get_contents(dirname($this->configDir) . '/VERSION')) ?: '0.0.0',
            'family.name' => $family['name'],
            'app.locale' => $family['locale'],
            'app.timezone' => $family['timezone'],
            'economy.allow_negative_balance' => false,
            'economy.xp_curve' => ['base' => 50, 'exponent' => 1.6],
            'milestones.default_spend_mode' => 'spend',
            'ui.sounds_enabled' => true,
            'ui.animation_intensity' => 'normal',
            'backups.retention_pre_update' => 5,
        ];

        foreach ($defaults as $key => $value) {
            $db->execute(
                'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP()',
                [$key, json_encode($value, JSON_THROW_ON_ERROR)]
            );
        }
    }

    /** @param array<string, mixed> $params */
    private function writeConfig(array $params, string $secret): void
    {
        $config = [
            'db' => [
                'host' => $params['db']['host'],
                'port' => $params['db']['port'],
                'name' => $params['db']['name'],
                'user' => $params['db']['user'],
                'password' => $params['db']['password'],
                'prefix' => $params['db']['prefix'] ?? '',
            ],
            'app' => [
                'locale' => $params['family']['locale'],
                'timezone' => $params['family']['timezone'],
                'debug' => false,
                'secret' => $secret,
            ],
        ];

        $php = "<?php\n\n// Generated by the Family Castel installer — do not commit.\n\nreturn "
            . var_export($config, true) . ";\n";

        $file = $this->configDir . '/config.php';
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write config directory.');
        }
        // Fail CLOSED — DB credentials must never keep umask permissions.
        if (!@chmod($tmp, 0640)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot restrict config file permissions.');
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot finalize config write.');
        }
    }

    private function writeLock(string $secret): void
    {
        $lock = json_encode([
            'installed_at' => gmdate('c'),
            'instance_id' => bin2hex(random_bytes(16)),
            // Derived, non-secret fingerprint (lets support match lock<->config).
            'secret_fingerprint' => substr(hash('sha256', $secret), 0, 16),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        if (file_put_contents($this->configDir . '/installed.lock', $lock, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write installed.lock');
        }
    }
}

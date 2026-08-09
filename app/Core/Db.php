<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Prepared statements only — string interpolation into SQL is
 * forbidden project-wide. Connection errors are re-thrown without credentials.
 */
final class Db
{
    private function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromParams(
        string $host,
        int $port,
        string $name,
        string $user,
        #[\SensitiveParameter] string $password,
    ): self {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException(
                'Database connection failed (host ' . $host . ':' . $port . ', database ' . $name . '): '
                . self::sanitizedDriverMessage($e),
                0
            );
        }

        return new self($pdo);
    }

    public static function fromConfig(Config $config): self
    {
        return self::fromParams(
            host: (string) $config->get('db.host', 'localhost'),
            port: (int) $config->get('db.port', 3306),
            name: (string) $config->get('db.name', ''),
            user: (string) $config->get('db.user', ''),
            password: (string) $config->get('db.password', ''),
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param list<mixed> $params */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function sanitizedDriverMessage(PDOException $e): string
    {
        // Driver messages never include the password, but be defensive: strip
        // anything after "using password" style fragments and cap length.
        $message = preg_replace('/\s+\(using password: \w+\)/i', '', $e->getMessage()) ?? 'connection error';

        return mb_substr($message, 0, 300);
    }
}

<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use PDO;
use PDOException;

/**
 * Enterprise database adapter with prefix safety, prepared statements,
 * transaction controls, and dual MySQL/SQLite engine portability.
 */
class Database
{
    protected ?PDO $pdo = null;
    protected string $prefix = '';
    protected string $driver = 'mysql';

    public function __construct(?PDO $pdo = null, string $prefix = '')
    {
        $this->prefix = $prefix;
        if ($pdo !== null) {
            $this->pdo = $pdo;
            $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        }
    }

    public static function createDefault(string $storagePath = ''): self
    {
        // Check for environment MySQL config, otherwise use persistent SQLite in storage/data
        $host = getenv('DB_HOST') ?: '';
        $dbname = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $prefix = getenv('DB_PREFIX') ?: 'soi_';

        if ($host && $dbname) {
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return new self($pdo, $prefix);
        }

        // Fallback to local SQLite storage for standalone / testing mode
        $dbFile = $storagePath ? rtrim($storagePath, '/\\') . '/database.sqlite' : ':memory:';
        if ($dbFile !== ':memory:') {
            $dir = dirname($dbFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $pdo = new PDO("sqlite:{$dbFile}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $instance = new self($pdo, $prefix);
        $instance->driver = 'sqlite';
        return $instance;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function tableName(string $baseTable): string
    {
        return $this->prefix . $baseTable;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}

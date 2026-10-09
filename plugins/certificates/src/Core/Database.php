<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use PDO;
use PDOException;
use InvalidArgumentException;

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
        if ($prefix !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix)) {
            throw new InvalidArgumentException('Database table prefix must be a valid SQL identifier prefix.');
        }
        $this->prefix = $prefix;
        if ($pdo !== null) {
            $this->pdo = $pdo;
            $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        }
    }

    public static function createDefault(string $storagePath = ''): self
    {
        // 1. If running inside host CMS, reuse host CMS PDO connection
        if (class_exists('\SOI\Core\Database')) {
            try {
                if (method_exists('\SOI\Core\Database', 'pdo')) {
                    $cmsPdo = \SOI\Core\Database::pdo();
                } elseif (method_exists('\SOI\Core\Database', 'getPdo')) {
                    $cmsPdo = \SOI\Core\Database::getPdo();
                }
            } catch (\Throwable) {
                // Try connecting if host CMS config constants are present
                if (defined('SOI_DB_HOST') && defined('SOI_DB_NAME') && defined('SOI_DB_USER')) {
                    try {
                        \SOI\Core\Database::connect([
                            'host'   => SOI_DB_HOST,
                            'name'   => SOI_DB_NAME,
                            'user'   => SOI_DB_USER,
                            'pass'   => defined('SOI_DB_PASS') ? SOI_DB_PASS : '',
                            'port'   => defined('SOI_DB_PORT') ? SOI_DB_PORT : 3306,
                            'prefix' => defined('SOI_DB_PREFIX') ? SOI_DB_PREFIX : 'soi_',
                        ]);
                        $cmsPdo = \SOI\Core\Database::pdo();
                    } catch (\Throwable) {}
                }
            }
            if (isset($cmsPdo) && $cmsPdo instanceof PDO) {
                $prefix = defined('SOI_DB_PREFIX') ? (string)SOI_DB_PREFIX : (getenv('DB_PREFIX') ?: 'soi_');
                return new self($cmsPdo, $prefix);
            }
        }

        // 2. Direct connection using CMS configuration constants if available
        if (defined('SOI_DB_HOST') && defined('SOI_DB_NAME') && defined('SOI_DB_USER')) {
            try {
                $port = defined('SOI_DB_PORT') ? (int)SOI_DB_PORT : 3306;
                $dsn = "mysql:host=" . SOI_DB_HOST . ";dbname=" . SOI_DB_NAME . ";charset=utf8mb4;port={$port}";
                $pdo = new PDO($dsn, SOI_DB_USER, defined('SOI_DB_PASS') ? (string)SOI_DB_PASS : '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $prefix = defined('SOI_DB_PREFIX') ? (string)SOI_DB_PREFIX : 'soi_';
                return new self($pdo, $prefix);
            } catch (\Throwable) {}
        }

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
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $baseTable)) {
            throw new InvalidArgumentException('Database table name must be a valid SQL identifier.');
        }

        if ($this->prefix !== '' && str_starts_with($baseTable, $this->prefix)) {
            return $baseTable;
        }

        return $this->prefix . $baseTable;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->rowCount();
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->prepareAndExecute($sql, $params);
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

    private function prepareAndExecute(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                is_resource($value) => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($parameter, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }
}

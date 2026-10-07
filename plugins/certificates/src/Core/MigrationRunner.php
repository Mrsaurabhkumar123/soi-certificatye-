<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use Exception;

/**
 * Migration Registry & Runner with version tracking and dialect normalization.
 * Fully operable via web request or CLI without manual SQL edits.
 */
class MigrationRunner
{
    protected Database $db;
    protected string $migrationsDir;

    public function __construct(Database $db, string $migrationsDir)
    {
        $this->db = $db;
        $this->migrationsDir = rtrim($migrationsDir, '/\\');
        $this->ensureMigrationTable();
    }

    protected function ensureMigrationTable(): void
    {
        $table = $this->db->tableName('cert_migrations');
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id INTEGER PRIMARY KEY " . ($this->db->getDriver() === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ",
            migration VARCHAR(128) NOT NULL UNIQUE,
            batch INT NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->getPdo()->exec($sql);
    }

    public function getAppliedMigrations(): array
    {
        $table = $this->db->tableName('cert_migrations');
        $rows = $this->db->fetchAll("SELECT migration FROM {$table} ORDER BY id ASC");
        return array_column($rows, 'migration');
    }

    public function getAvailableMigrations(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $files = scandir($this->migrationsDir);
        $migrations = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.sql')) {
                $migrations[] = $file;
            }
        }
        sort($migrations);
        return $migrations;
    }

    public function getPendingMigrations(): array
    {
        $applied = $this->getAppliedMigrations();
        $available = $this->getAvailableMigrations();
        return array_values(array_diff($available, $applied));
    }

    public function runPending(): array
    {
        $pending = $this->getPendingMigrations();
        $appliedNow = [];
        $batch = (int)$this->db->fetchValue("SELECT COALESCE(MAX(batch), 0) + 1 FROM " . $this->db->tableName('cert_migrations'));

        foreach ($pending as $migrationFile) {
            $filePath = $this->migrationsDir . DIRECTORY_SEPARATOR . $migrationFile;
            $sqlContent = file_get_contents($filePath);
            if ($sqlContent === false) {
                throw new Exception("Unable to read migration file: {$migrationFile}");
            }

            $this->executeSqlStatements($sqlContent);

            $table = $this->db->tableName('cert_migrations');
            $this->db->execute(
                "INSERT INTO {$table} (migration, batch) VALUES (:migration, :batch)",
                ['migration' => $migrationFile, 'batch' => $batch]
            );

            $appliedNow[] = $migrationFile;
        }

        return $appliedNow;
    }

    protected function executeSqlStatements(string $rawSql): void
    {
        $isSqlite = $this->db->getDriver() === 'sqlite';
        $prefix = $this->db->getPrefix();

        // Normalize dialect if SQLite
        $statements = explode(';', $rawSql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt)) {
                continue;
            }

            // Remove line comments
            $stmt = preg_replace('/^--.*$/m', '', $stmt);
            $stmt = trim($stmt);
            if (empty($stmt)) {
                continue;
            }

            // Apply table prefixing
            if (!empty($prefix)) {
                $stmt = preg_replace('/\b(cert_[a-z0-9_]+)\b/i', $prefix . '$1', $stmt);
            }

            if ($isSqlite) {
                // Adapt MySQL DDL to SQLite
                $stmt = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $stmt);
                $stmt = str_replace('TINYINT(1)', 'INTEGER', $stmt);
                $stmt = str_replace('LONGTEXT', 'TEXT', $stmt);
                // Strip standalone MySQL KEY indexes from CREATE TABLE syntax
                $stmt = preg_replace('/,\s*KEY\s+[a-z0-9_]+\s*\([^)]+\)/i', '', $stmt);
                $stmt = preg_replace('/,\s*UNIQUE KEY\s+[a-z0-9_]+\s*\(([^)]+)\)/i', ', UNIQUE($1)', $stmt);
            }

            $this->db->getPdo()->exec($stmt);
        }
    }
}
